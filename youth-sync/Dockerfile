FROM node:22-alpine
WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm install
COPY . .
# The browser calls /api on this same site. The server proxies that to the API,
# so the login cookie is first-party on every browser.
ENV VITE_API_BASE_URL=/api
RUN npm run build
EXPOSE 8080
CMD ["node", "server.mjs"]
