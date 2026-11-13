# Публикация на GitHub

Название репозитория: `reservation-core`.

Описание: `Standalone PHP reservation and payment coordination module with PostgreSQL locking, transactional outbox and concurrency tests.`

В исходниках нет реальных клиентских данных. Репозиторий можно сделать публичным для портфолио. Не добавляй рабочие `.env`, токены, дампы или клиентские файлы.

## Через GitHub CLI

Распакуй архив, установи Git и GitHub CLI, войди в свой аккаунт. Затем из каталога проекта выполни:

```sh
gh auth login --hostname github.com --web
git init -b main
git add .
git commit -m "Implement reservation and payment coordination module"
gh repo create reservation-core --public --source . --remote origin --push
```

Команды создают новый репозиторий и отправляют исходники. Если Git требует имя и email автора, настрой их в соответствии со своим профилем; можно использовать GitHub noreply email.

## Через уже созданный пустой репозиторий

Можно создать пустой `reservation-core` в интерфейсе GitHub, не добавляя README, лицензию и gitignore: эти файлы уже входят в проект. После этого отправить код из локального каталога или передать ссылку помощнику, у которого есть право записи в этот репозиторий.

GitHub Actions настроен в `.github/workflows/ci.yml`. После первой отправки открой вкладку Actions и проверь фактический результат запуска. Зелёный статус в документации не заменяет выполненный workflow.

История публикации и коммитов должна отражать реальные действия. Дата создания репозитория хранится GitHub отдельно от дат git-коммитов.
