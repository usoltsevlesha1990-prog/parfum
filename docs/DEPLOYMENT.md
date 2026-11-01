# Публикация одного PHP-файла

Workflow `.github/workflows/deploy-review.yml` отправляет только
`review-site/index.php` по SFTP в
`/home/parfumplace/web/parfumplace.ru/public_html/catalog-review/index.php`.
Доступ: https://parfumplace.ru/catalog-review/index.php

Это автономный PHP 8.2+ файл без зависимостей и подключения к БД: он содержит
только снимок брендов, описаний ароматов и цен больших флаконов из бэкапа
01.11.2026. Список, поиск, фильтр бренда и пагинация формируются PHP через
параметры URL. Нет дизайна, корзины и оформления заказа.

Ключ деплоя хранится только в GitHub Actions secret `DEPLOY_SSH_KEY`.
Публичная часть установлена для пользователя `parfumplace` с ограничением
`restrict,command="internal-sftp"`. Отпечаток Ed25519-ключа сервера проверяется:
`SHA256:A3zj4MdiiGuCl2TK9enHX8q7QV7nQ/uL6W2pnNY+gZA`.
Если серверный ключ сменился, остановить загрузку и проверить новый отпечаток
через доверенный канал; не отключать проверку.

Пуш в main запускает процесс автоматически; возможен запуск вручную через
GitHub Actions → Publish catalog review. Перед загрузкой выполняются проверка
синтаксиса PHP и HTTP-тесты всех описаний. После — HTTPS-проверка сайта.
Результат загрузки и результат HTTP-проверки учитываются отдельно.
Существующие HTML-страницы на сервере не удаляются; открывайте index.php явно.
Главная страница сайта и исходная БД не меняются.

Пересборка из приватного локального извлечения каталога:
`python3 tools/build_single_php.py`. Тесты:
`PHP_BIN=/workspace/.parfum-runtime/bin/php python3 tests/Feature/single_php.py -v`.
В CI используется обычный `php` из образа GitHub runner.
