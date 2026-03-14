## 環境構築

1. public_html 配下の .env.example を複製し .env を作成

2. 作成した .env の中身を下記に変更し保存

    ~~~ sh
    APP_NAME=Laravel
    APP_ENV=local
    APP_KEY=base64:oMffk4c/dYnvnkSByhVFSh99h6hWO/ISRgPpBp8DwS0=
    APP_DEBUG=true
    APP_TIMEZONE=Asia/Tokyo
    APP_URL=http://localhost

    APP_LOCALE=ja
    APP_FALLBACK_LOCALE=ja
    APP_FAKER_LOCALE=ja_JP

    APP_MAINTENANCE_DRIVER=file
    # APP_MAINTENANCE_STORE=database

    BCRYPT_ROUNDS=12

    LOG_CHANNEL=daily
    LOG_STACK=single
    LOG_DEPRECATIONS_CHANNEL=null
    LOG_LEVEL=debug

    DB_CONNECTION=pgsql
    DB_HOST=db
    DB_PORT=5432
    DB_DATABASE=possystem
    DB_USERNAME=root
    DB_PASSWORD=Root2020

    SESSION_DRIVER=file
    SESSION_LIFETIME=120
    SESSION_ENCRYPT=false
    SESSION_PATH=/
    SESSION_DOMAIN=null

    BROADCAST_CONNECTION=log
    FILESYSTEM_DISK=local
    QUEUE_CONNECTION=database

    CACHE_STORE=database
    CACHE_PREFIX=

    MEMCACHED_HOST=127.0.0.1

    REDIS_CLIENT=phpredis
    REDIS_HOST=127.0.0.1
    REDIS_PASSWORD=null
    REDIS_PORT=6379

    MAIL_MAILER=log
    MAIL_HOST=127.0.0.1
    MAIL_PORT=2525
    MAIL_USERNAME=null
    MAIL_PASSWORD=null
    MAIL_ENCRYPTION=null
    MAIL_FROM_ADDRESS="hello@example.com"
    MAIL_FROM_NAME="${APP_NAME}"

    AWS_ACCESS_KEY_ID=
    AWS_SECRET_ACCESS_KEY=
    AWS_DEFAULT_REGION=us-east-1
    AWS_BUCKET=
    AWS_USE_PATH_STYLE_ENDPOINT=false

    VITE_APP_NAME="${APP_NAME}"
    ~~~

3. 起動

    ~~~ sh
    cd docker
    ~~~

    ~~~ sh
    docker compose up -d
    ~~~

4. 起動したらコンテナに入る

    ~~~ sh
    docker compose exec app bash
    ~~~

5. コンテナ内 `root@xxxxxxxxxxxx:/var/www#` の状態で下記を実行

    ~~~ sh
    chown -R apache:apache /var/www/storage
    ~~~

    ~~~ sh
    chmod -R 775 /var/www/storage
    ~~~

    ~~~ sh
    composer install
    ~~~

    ~~~ sh
    php artisan key:generate
    ~~~

    ~~~ sh
    php artisan migrate --seed
    ~~~

6. ブラウザで確認

    http://localhost/admin/login

    ※20250520現在、ログイン入力画面が表示される所まででOK.
    下記で入ろうとするとエラーになりますが一旦ここまで。

    ~~~
    admin
    0000
    ~~~
