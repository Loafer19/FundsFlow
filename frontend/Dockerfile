FROM oven/bun:latest AS builder

WORKDIR /app

COPY package.json ./
RUN bun i

COPY . .

RUN bun run prod

FROM nginx:alpine

COPY --from=builder /app/dist /usr/share/nginx/html

EXPOSE 80
