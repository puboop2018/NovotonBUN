# Pre-baked MySQL test image.
#
# seed.sql is produced by build.sh on the maintainer's machine from a clean
# CS-Cart 4.20.1 install with the three addons enabled. It is NEVER committed
# to git — it is consumed at image-build time and embedded into the image
# layer via Docker's official mysql entrypoint.
#
# See docker/test-db/README.md for the rebuild procedure.
FROM mysql:8.0

ENV MYSQL_DATABASE=cscart
ENV MYSQL_USER=cscart
ENV MYSQL_PASSWORD=cscart
ENV MYSQL_ROOT_PASSWORD=root

# Init script directory is processed alphabetically by the mysql entrypoint
# on first container start. Keeping a single compressed dump keeps image
# layers small and avoids ~minute-long re-imports of individual .sql files.
COPY seed.sql.gz /docker-entrypoint-initdb.d/00_cscart_seed.sql.gz

HEALTHCHECK --interval=5s --timeout=3s --start-period=30s --retries=20 \
    CMD mysqladmin ping -h 127.0.0.1 -u root -p"$MYSQL_ROOT_PASSWORD" || exit 1
