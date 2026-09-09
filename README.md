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
- Nginx (servidor web). Los eventos de dominio viajan por Symfony Messenger con el
  transporte Doctrine (tabla `messenger_messages` de la propia base de datos, creada por las
  migraciones) y los consume el servicio `worker`; hay reintentos y cola de fallidos.

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

**Productos**

| Método | Ruta | Respuesta |
|--------|------|-----------|
| `POST` | `/v1/products` | `201` producto creado |
| `GET` | `/v1/products` | `200` página (`products`, `page`, `pageSize`, `total`, `pages`) |
| `GET` | `/v1/products/{id}` | `200` producto |
| `GET` | `/v1/products/by-code/{code}` | `200` producto por su código |
| `PATCH` | `/v1/products/{id}` | `200` nombre, código y/o precio actualizados |
| `PATCH` | `/v1/products/{id}/stock` | `200` stock fijado (`quantity`) o ajustado (`delta`) |
| `DELETE` | `/v1/products/{id}` | `204` retirada lógica: desaparece del catálogo y no admite nuevas líneas |

El listado acepta `pageNumber`, `pageSize`, `q` (nombre o código), `minPrice`, `maxPrice`,
`inStock` y `sort` (`name`, `price` o `code`, con `-` delante para orden descendente).

**Carritos y pedidos**

| Método | Ruta | Respuesta |
|--------|------|-----------|
| `POST` | `/v1/carts` | `201` carrito creado con sus líneas |
| `GET` | `/v1/carts` | `200` carritos del llamante |
| `GET` | `/v1/carts/{id}` | `200` carrito con sus líneas y totales |
| `PUT` | `/v1/carts/{cartId}/products/{productId}/add` | `200` carrito con la línea añadida |
| `PATCH` | `/v1/carts/{cartId}/items/{itemId}` | `200` cantidad de la línea (`quantity`; `0` la elimina) |
| `DELETE` | `/v1/carts/{cartId}/items/{itemId}` | `204` línea eliminada, stock devuelto |
| `PUT` | `/v1/carts/{id}/checkout` | `200` carrito pagado; crea el pedido |
| `PUT` | `/v1/carts/{id}/deliver` | `200` carrito entregado |
| `DELETE` | `/v1/carts/{id}` | `204` carrito cancelado, stock devuelto |
| `GET` | `/v1/orders/{id}` | `200` pedido con sus líneas y su total |

El carrito responde con `subtotal`, `total`, `itemCount` y `currency`; sus líneas llevan
cantidad. Un carrito mantiene una sola moneda: mezclarlas es un `409`.

Estados: `pending → paid → delivered`, y `pending` o `paid → canceled`. Sólo un carrito
pendiente admite cambios de líneas y *checkout*; cancelar devuelve el stock reservado.
Fuera de esas transiciones la respuesta es `409`.

**Operación**

| Método | Ruta | Respuesta |
|--------|------|-----------|
| `GET` | `/health` | `200` cuando la base de datos y la cola responden, `503` si no |

`/health` y `/api/docs` son públicos aunque la autenticación esté activada.

### Reintentos seguros: `Idempotency-Key`

`POST /v1/carts`, `PUT .../add` y `PUT .../checkout` aceptan la cabecera `Idempotency-Key`.
La primera petición guarda su respuesta junto a una huella del cuerpo; repetirla con la
misma clave y el mismo cuerpo devuelve la respuesta guardada con `Idempotent-Replayed: true`
sin volver a ejecutar nada, y con un cuerpo distinto responde `422`. Las claves caducan a
las `IDEMPOTENCY_TTL` (24 h) y `bin/console idempotency:purge-expired` limpia las vencidas.

La clave se reclama *antes* de ejecutar la petición (un `INSERT` sobre la clave primaria, así
que de varias peticiones simultáneas con la misma clave sólo una se ejecuta). Mientras la
original sigue en curso, repetir la clave responde `409` («retry in a moment»). Si la
original murió sin llegar a guardar su respuesta —el proceso cayó entre el *commit* y el
almacenamiento—, la clave **no se libera**: pasados 5 minutos la repetición responde `409`
indicando que aquella petición nunca informó de su resultado, y el cliente comprueba si
surtió efecto (`GET`) y usa una clave nueva. Liberarla y ejecutar el reintento «de verdad»
sería crear un segundo carrito o reservar el stock dos veces, justo lo que la cabecera
existe para evitar.

### Autenticación (desactivada por defecto)

Con `API_TOKENS` vacía la API es abierta, que es como está pensada la prueba. Al definirla
—pares `token:cliente` separados por comas, el token primero:
`API_TOKENS="s3cret-for-alice:alice,s3cret-for-bob:bob"`— cada petición a `/v1` necesita
`Authorization: Bearer <token>` o `X-API-Key: <token>`, y responde `401` sin ella. El
carrito pasa entonces a tener dueño: `GET /v1/carts` sólo lista los del llamante y operar
sobre el carrito de otro es un `404`. `/health` y `/api/docs` siguen abiertos, y el
documento OpenAPI describe el despliegue que lo sirve: con `API_TOKENS` definida deja de
anunciar el acceso anónimo en `security` (un cliente generado a partir de él enviaría la
credencial), y sin ella lo incluye.

Los dos puntos son el separador, así que ni el token ni el cliente pueden llevar uno:
`t:acme:alice` no dice cuál de los dos separa —¿token `t:acme` para `alice`, o token `t`
para `acme:alice`?— y se rechaza al arrancar en vez de elegir por el operador. El secreto lo
genera el despliegue y basta con generarlo sin `:`; un identificador de cliente que lleve uno
se mapea a un nombre para esta variable.

### Lo que se paga queda fijado

Una línea apunta al producto en vez de guardar una copia de su precio, que es lo correcto
mientras el carrito está pendiente —el cliente ve el precio de hoy— y deja de serlo en cuanto
se paga. `Cart::pay()` copia el precio unitario de cada línea, así que el total de un carrito
pagado ya no se mueve con el catálogo: cambiar el importe de un producto no reescribe una
compra terminada, y cambiarlo de moneda no vuelve ilegible el carrito que la contiene. El
`Order` era ya una copia; ahora el carrito también lo es a partir del pago.

Cancelar un carrito pagado alcanza a su pedido: `orders.canceled_at` queda escrito y la
confirmación encolada en el checkout no hace nada al consumirse, en vez de confirmar una
compra que el cliente había anulado.

### Reservas caducadas

El stock se reserva al añadir la línea, así que un carrito abandonado lo retendría para
siempre. `bin/console cart:release-expired` cancela los carritos pendientes que llevan más
de `CART_RESERVATION_TTL` (30 min) parados y devuelve sus unidades; conviene ejecutarlo
periódicamente (cron o un `worker` con `--time-limit`).

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

**En local, sin Docker** (sólo PHP 8.3+ y Composer):

```bash
cd app
composer install
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
| `DATABASE_URL` | `app/.env*` | DSN Doctrine (`app/.env.test` usa SQLite) |
| `MESSENGER_TRANSPORT_DSN` | `app/.env` / compose | transporte Messenger de los eventos de dominio (`doctrine://default`: la cola vive en la base de datos de la aplicación) |
| `API_ROUTE_PREFIX` | `app/.env` | prefijo de las rutas de la API (`/api`) |
| `CORS_ALLOW_ORIGIN` | `app/.env` | orígenes permitidos por nelmio/cors |
| `API_TOKENS` | `app/.env` / compose | pares `token:cliente` separados por comas (el token primero, un solo `:` por par); vacía deja la API abierta |
| `CART_RESERVATION_TTL` | `app/.env` | segundos que un carrito pendiente retiene su stock (1800) |
| `IDEMPOTENCY_TTL` | `app/.env` | segundos que se recuerda una `Idempotency-Key` (86400) |

Los ficheros versionados sólo contienen valores de desarrollo evidentes; nada real se
escribe en el repositorio (`.env` raíz está en `.gitignore`).
