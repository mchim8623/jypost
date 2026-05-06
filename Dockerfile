FROM php:8.2-apache

# 安装 MySQL 扩展（必须）
RUN docker-php-ext-install pdo pdo_mysql mysqli

# 启用 .htaccess 重写支持
RUN a2enmod rewrite

# 复制项目文件
WORKDIR /var/www/html
COPY . /var/www/html

# 设置文件权限
RUN chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type d -exec chmod 755 {} \; \
    && find /var/www/html -type f -exec chmod 644 {} \;

EXPOSE 80
