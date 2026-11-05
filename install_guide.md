Check

command -v php && php -v || echo "php not found"

Install PHP

sudo apt update && sudo apt install -y php-cli

Installed PHP Path

command -v php && php -v

Path

echo $PATH

evalate Path

/usr/bin/php -v

Updated Project steps and implemented temporary fix

export PATH=/usr/bin:$PATH && command -v php && php -v && php sync-database.php pull

Updated installation steps and tracked tasks

sudo apt install -y php-mysql

export PATH=/usr/bin:$PATH && php -m | grep -E "mysqli|mysql" || true && php sync-database.php pull