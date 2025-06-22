FROM php:8.2-cli

RUN apt-get update && apt-get install -y curl

WORKDIR /app

COPY . .

EXPOSE 10000

CMD ["php", "-S", "0.0.0.0:10000", "-t", "."]
