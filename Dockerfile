FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends gcc \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install ffi \
    && docker-php-ext-enable ffi

WORKDIR /var/www/html
COPY . /var/www/html

RUN gcc /var/www/html/csv_reader.c -shared -fPIC -O3 -o /var/www/html/csv_reader.so

RUN { \
      echo "ffi.enable=true"; \
      echo "upload_max_filesize=2048M"; \
      echo "post_max_size=2048M"; \
      echo "memory_limit=4096M"; \
      echo "max_execution_time=0"; \
      echo "max_input_time=0"; \
      echo "session.gc_maxlifetime=3600"; \
    } > /usr/local/etc/php/conf.d/app.ini

EXPOSE 80
