# OpenAPI / Postman (pendiente)

Fase 6 del roadmap: generar especificación OpenAPI 3.x y colección Postman a partir de:

- Rutas v1: `php artisan route:list --path=api/v1`
- Formato de respuesta: `App\Support\ApiResponse`

Herramientas sugeridas: `knuckleswtf/scribe` o export manual desde Postman tras importar `{{baseUrl}}/api/v1`.
