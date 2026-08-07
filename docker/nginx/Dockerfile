ARG APP_IMAGE=dashlog-app
FROM ${APP_IMAGE} AS source

FROM nginx:alpine
COPY --from=source /var/www/html/public /var/www/html/public
COPY default.conf /etc/nginx/conf.d/default.conf
