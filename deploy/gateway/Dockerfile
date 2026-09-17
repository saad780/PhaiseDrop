FROM nginx:1.28-alpine

COPY deploy/gateway/nginx.conf /etc/nginx/nginx.conf
RUN chmod 0644 /etc/nginx/nginx.conf

USER nginx
EXPOSE 8080
