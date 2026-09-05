# Siroko **Senior** Code Challenge

## Descripción

API JSON de productos y carrito de compra para Siroko, desarrollada en **Symfony 7.4**
(PHP 8.3+) sobre **API Platform 4**, con estructura DDD y arquitectura hexagonal, bus de
comandos/consultas (Tactician) y entorno de desarrollo con Docker.

## Modelado DDD

El dominio se compone de **Product**, **Cart** y **CartItem**, cada uno con sus *value
objects* (`ProductId`, `ProductCode`, `Name`, `Price`, `Quantity`, `CartId`, `CartStatus`,
`ItemId`) y su repositorio (puerto en `Domain/Repository`, adaptador Doctrine en
`Infrastructure/Persistence`).

```
app/src/Cart/
├── Domain/          entidades, value objects, excepciones y puertos (sin framework)
├── Application/     comandos y consultas con sus handlers; read models (DTO) de la API
└── Infrastructure/  API (controladores, recursos API Platform, mapeo de errores),
                     persistencia Doctrine (tipos, mapeo XML, migraciones, fixtures),
                     bus de comandos y cola de eventos
```

Reglas de negocio que la API protege:

- El stock se reserva al añadir un producto al carrito (una unidad por línea) y se devuelve
  al quitar la línea. Las operaciones de stock son `UPDATE` atómicos y condicionales.
- Sólo un carrito **pendiente** admite añadir o quitar líneas y hacer *checkout*. Sobre un
  carrito ya pagado las tres operaciones responden `409`.
- El código de producto es único (`409` si se repite).
- Todo id de la API es un UUID; un id con otro formato no llega a ningún controlador.

## Tecnología

- Docker / Docker Compose
- PHP 8.4 (8.3 soportado), Symfony 7.4, API Platform 4, Doctrine ORM 3 / DBAL 3
- MySQL 8 (persistencia). La suite de tests corre también sobre SQLite.
- Nginx (servidor web), RabbitMQ (eventos de dominio asíncronos; la infraestructura está
  cableada -transporte, reintentos, cola de fallidos, *worker*- pero la prueba no define
  todavía ningún evento de negocio).

## Puesta en marcha

1. Clonar el proyecto.
2. (Opcional) `cp .env.example .env` y ajustar valores. Todas las variables tienen un valor
   por defecto de desarrollo en `docker-compose.yaml`, así que este paso se puede omitir.
3. `make up` (o `docker compose up -d --build`).
4. `make install` — instala las dependencias PHP dentro del contenedor.
5. `make migrate` — crea las bases de datos de desarrollo y de test y ejecuta las migraciones.
6. (Opcional) `make fixtures` — carga 20 productos y un carrito de ejemplo.
7. La API está en `http://localhost:8080/api` y su documentación OpenAPI en
   `http://localhost:8080/api/docs`.

`make help` lista el resto de targets (`sh`, `logs`, `test`, `stan`, `cs`, `lint`, `check`).

La aplicación nunca se conecta como `root`: el usuario `MYSQL_USER` es dueño de la base de
datos de la aplicación y de la de tests (`<MYSQL_DATABASE>_test`, creada por
`docker/mysql/init` en el primer arranque).

## API

Prefijo `/api` (variable `API_ROUTE_PREFIX`). Documentación interactiva en `/api/docs`.

| Método | Ruta | Respuesta |
|--------|------|-----------|
| `POST` | `/v1/products` | `201` producto creado |
| `GET` | `/v1/products?pageNumber=&pageSize=` | `200` página (`products`, `page`, `pageSize`, `total`, `pages`) |
| `GET` | `/v1/products/{id}` | `200` producto |
| `POST` | `/v1/carts` | `201` carrito creado con sus líneas |
| `GET` | `/v1/carts/{id}` | `200` carrito |
| `PUT` | `/v1/carts/{cartId}/products/{productId}/add` | `200` carrito con la nueva línea |
| `DELETE` | `/v1/carts/{cartId}/items/{itemId}` | `204` |
| `PUT` | `/v1/carts/{id}/checkout` | `200` carrito pagado |

Todos los errores usan el mismo contrato, [RFC 7807](https://www.rfc-editor.org/rfc/rfc7807)
(`Content-Type: application/problem+json`):

```json
{"type": "about:blank", "title": "Conflict", "status": 409, "detail": "Cart is not pending"}
```

- `400` petición mal formada (JSON inválido, campo ausente o de tipo incorrecto, cantidad,
  precio o moneda inválidos, UUID inválido en el cuerpo).
- `404` carrito, línea o producto inexistente; también un id de ruta que no es un UUID.
- `405` método no permitido.
- `409` carrito no pendiente, producto sin stock, código de producto duplicado.
- `500` error inesperado: el detalle queda en el log, nunca en la respuesta.

## Ejecución de tests

La suite (`app/bin/phpunit`) está organizada en dos *suites*: `unit` (dominio y aplicación,
sin base de datos) e `infrastructure` (tests funcionales HTTP, repositorios, tipos Doctrine,
fixtures). Cada test corre dentro de una transacción que se deshace al terminar
(`dama/doctrine-test-bundle`).

**En local, sin Docker** (sólo PHP 8.3+ y Composer; `ext-amqp` no es necesaria para los tests):

```bash
cd app
composer install --ignore-platform-req=ext-amqp
composer test            # toda la suite sobre SQLite (app/var/test.db, esquema creado por tests/bootstrap.php)
composer test:unit       # sólo dominio y aplicación
composer test:infra      # sólo infraestructura
```

**Contra MySQL** (dentro del contenedor, tras `make migrate`):

```bash
make test                # toda la suite, incluido el grupo `mysql`
make test-unit
make test-func
```

El grupo `mysql` (`#[Group('mysql')]`) agrupa los tests que dependen de semántica exclusiva
de MySQL -bloqueos de fila con `FOR UPDATE`- y está excluido por defecto en
`phpunit.dist.xml`; se ejecuta con `php bin/phpunit --group mysql` cuando `DATABASE_URL`
apunta a MySQL, que es lo que hace la CI.

La base de datos se elige con `DATABASE_URL`: `.env.test` apunta a SQLite y una variable
de entorno real la sustituye (la CI exporta un DSN de MySQL y ejecuta las migraciones antes
de la suite).

## Calidad

```bash
composer cs        # php-cs-fixer (reglas @Symfony + @PER-CS), sólo comprueba
composer cs:fix    # aplica el estilo
composer stan      # PHPStan nivel 8 con las extensiones de Symfony, Doctrine y PHPUnit
composer lint      # lint:container, lint:yaml config, doctrine:schema:validate (mapeo)
composer check     # validate + cs + stan + lint + test, en ese orden (lo mismo que la CI)
```

Los mismos targets existen en el `Makefile` (`make cs`, `make stan`, `make lint`,
`make check`) para ejecutarlos dentro del contenedor.

### Integración continua

`.github/workflows/ci.yml` ejecuta en PHP 8.3 y 8.4:

- `lint`: `composer validate --strict`, estilo, PHPStan y los *linters* de Symfony/Doctrine.
- `test`: con un servicio `mysql:8`, crea la base de datos de test, ejecuta las
  migraciones, comprueba que migraciones y mapeo coinciden (`doctrine:schema:validate`),
  corre la suite con cobertura (`pcov`), el grupo `mysql`, y falla si la cobertura de
  líneas baja del 80 % (`bin/coverage-threshold`).

## Variables de entorno

| Variable | Dónde | Uso |
|----------|-------|-----|
| `APP_ENV`, `APP_SECRET` | `.env` raíz / compose | entorno Symfony; el secreto de producción viene siempre del entorno |
| `MYSQL_DATABASE`, `MYSQL_USER`, `MYSQL_PASSWORD`, `MYSQL_ROOT_PASSWORD` | `.env` raíz / compose | base de datos; compose compone `DATABASE_URL` con ellas |
| `RABBITMQ_USER`, `RABBITMQ_PASSWORD` | `.env` raíz / compose | broker; compose compone `MESSENGER_TRANSPORT_DSN` |
| `DATABASE_URL` | `app/.env*` | DSN Doctrine (`app/.env.test` usa SQLite) |
| `MESSENGER_TRANSPORT_DSN` | `app/.env` | transporte Messenger de los eventos de dominio |
| `API_ROUTE_PREFIX` | `app/.env` | prefijo de las rutas de la API (`/api`) |
| `CORS_ALLOW_ORIGIN` | `app/.env` | orígenes permitidos por nelmio/cors |

Los ficheros versionados sólo contienen valores de desarrollo evidentes; nada real se
escribe en el repositorio (`.env` raíz está en `.gitignore`).
