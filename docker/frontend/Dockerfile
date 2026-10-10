FROM node:22-bookworm-slim
WORKDIR /app
COPY frontend/package*.json ./
RUN npm ci
COPY frontend/ ./
CMD ["npm", "start", "--", "--host", "0.0.0.0", "--port", "4200", "--poll", "1000"]
