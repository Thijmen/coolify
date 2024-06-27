# Contributing

> "First, thanks for considering to contribute to my project.
  It really means a lot!" - [@andrasbacsai](https://github.com/andrasbacsai)

You can ask for guidance anytime on our
[Discord server](https://coollabs.io/discord) in the `#contribution` channel.

## Code Contribution

### 1) Setup your development environment

- You need to have Docker Engine (or equivalent) [installed](https://docs.docker.com/engine/install/) on your system.
- For better DX, install [Spin](https://serversideup.net/open-source/spin/).

### 2) Set your environment variables

- Copy [.env.development.example](./.env.development.example) to .env.

## 3) Start & setup Coolify

### 3.1) With Spin (recommended)
- Run `spin up` - You can notice that errors will be thrown. Don't worry.
  - If you see weird permission errors, especially on Mac, run `sudo spin up` instead.

### 3.2) Without Spin
Ensure the following lines in your .env file are set correctly.But it may vary depending on your setup and OS. Start like this if unsure:
```.env
- APP_URL=http://localhost
- USERID=1000
- GROUPID=1000
```

Next, run `composer install`.

To start the Docker environment, use the following command:
- `docker compose -f docker-compose.yml -f docker-compose.dev.yml up`

After the Docker environment is up, run the following Laravel commands:
- `docker exec -it coolify php artisan key:generate`
- `docker exec -it coolify php artisan storage:link`
- `docker exec -it coolify php artisan migrate:fresh --seed`

### 4) Start development
You can login your Coolify instance at `localhost:8000` with `test@example.com` and `password`.

Your horizon (Laravel scheduler): `localhost:8000/horizon` - Only reachable if you logged in with root user.

Mails are caught by Mailpit: `localhost:8025`

### 5) Run tests

On test environment, startup as: `docker compose -f docker-compose.yml -f docker-compose.dev.yml -f docker-compose.tests.yml up`

Then run tests: `docker exec -it coolify-coolify-tests-1 php artisan test`

## New Service Contribution
Check out the docs [here](https://coolify.io/docs/knowledge-base/add-a-service).

