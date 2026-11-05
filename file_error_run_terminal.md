Start the MariaDB/MySQL service each time you open the codespace:
Run this in the terminal:

superadmin/accounting/events/event-reports.php

Wait a few seconds for the service to start

mysqld_safe A mysqld process already exists
sudo mysql -e "CREATE DATABASE IF NOT EXISTS srcolony;" && sudo mysql srcolony < /dev/null && echo "Database ready"
cd /workspaces/SRCOLONY-VIEWS && php -S localhost:8000

(Optional, first time only) Create your database if it doesn't exist:

sudo mysql -e "CREATE DATABASE IF NOT EXISTS srcolony;"


MariaDB Install

sudo apt update && sudo apt install -y mariadb-server

sudo service mysql start || sudo service mariadb start || sudo /etc/init.d/mysql start || sudo /etc/init.d/mariadb start || true

sudo ss -ltnp | grep 3306 || sudo netstat -plnt | grep 3306 || true

sudo mysql -e "CREATE DATABASE IF NOT EXISTS srcolony;" && sudo mysql -e "SELECT Host,User FROM mysql.user LIMIT 5;"

export PATH=/usr/bin:$PATH && /usr/bin/php sync-database.php pull


RUN APPLICATION

start the PHP development server to run your application
php -S localhost:8000

