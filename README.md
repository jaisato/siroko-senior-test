# Siroko **Senior** Code Challenge

## Descripción

El proyecto es una prueba técnica de una API de carrito y productos para Siroko desarrollada en Symfony 7.3, estructura DDD y arquitectura hexagonal con entorno de desarrollo automatizado con Docker.

## Modelado DDD

El dominio se compone del modelo de Productos (Product), Carrito (Cart) y items del carrito (CartITem).
Cada modelo tiene sus propios Value Objects para cada atributo, además de su propio repositorio.

## Tecnología utilizada

- Docker
- PHP 8.3
- Symfony 7.3
- Doctrine ORM
- MySQL (BD / persistencia)
- Nginx (Web Server/Proxy Web)
- RabbitMQ (para eventos asíncronos). Nota: está preparado el desarrollo básico para usarlo pero en la prueba técnica no se da el caso de uso
- Git (GitHub)

## Instrucciones

1. Descargar el proyecto desde GitHub.
2. Desde la raíz del proyecto, ejecutar: docker compose up -d --build
3. Para entrar en contenedor PHP ejecutar: docker compose exec -it -u www-data php bash
4. Una vez dentro del contenedor PHP, ir a directorio app/ y ejecutar: composer install
5. Crear BD (si no existe): php bin/console doctrine:database:create --if-not-exists
6. Ejecutar migraciones BD: php bin/console doctrine:migrations:migrate -n
6. Popular BD con datos de prueba: php php bin/console doctrine:fixtures:load --env=dev -n
5. El proyecto ya debería estar disponible en la URL: http://localhost:8080/api/docs

## Documentación API

La documentación de la API está disponible una vez levantado el entorno con Docker en la url http://localhost:8080/api/docs

## Ejecución Tests

Con el entorno levantado de desarrollo, entrar en el contenedo PHP: docker compose exec -it -u www-data php bash

- Ir a directorio app/: cd app
- Borrar cache test (si es necesario): php bin/console cache:clear --env=test
- Crear BD para tests: php bin/console doctrine:database:create --env=test --if-not-exists
- Ejecutar migraciones: php bin/console doctrine:migrations:migrate -n --env=test
- Ejecutar PHPUnit: php bin/phpunit