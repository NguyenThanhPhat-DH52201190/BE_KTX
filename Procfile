web: php artisan storage:link && php artisan migrate --force && php artisan serve --host=0.0.0.0 --port=$PORT
worker: php artisan queue:work --tries=3 --backoff=10,30,60