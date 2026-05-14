FROM node:20-alpine

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /app

EXPOSE 5173

ENV CHOKIDAR_USEPOLLING=true \
    CHOKIDAR_INTERVAL=400

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["npm", "run", "dev", "--", "--host", "0.0.0.0"]
