# Database Configuration Setup

## For Local Development

The application uses a fallback configuration system:

1. **config.local.php** (if it exists) - Local database credentials
2. **Default values** - Falls back to localhost with no password

## For Hosting Setup

**IMPORTANT**: The 500 error you're seeing is likely due to incorrect database credentials on the hosting server.

### Steps to Fix:

1. **Get your hosting database credentials** from your hosting provider's control panel:
   - Database hostname (e.g., `mysql.example.com`, `localhost`)
   - Database username
   - Database password
   - Database name

2. **Create config/config.local.php** on your hosting server:
   ```php
   <?php
   $db_host = 'your_database_host';      // Get this from hosting provider
   $db_user = 'your_database_user';      // Get this from hosting provider
   $db_password = 'your_database_pass';  // Get this from hosting provider
   $db_name = 'your_database_name';      // Get this from hosting provider
   $db_port = 3306;                      // Usually 3306 for MySQL
   ?>
   ```

3. **Upload config/config.local.php** to your hosting server via FTP/SFTP

4. **Test** - Refresh the login page. The 500 error should be gone.

### Finding Your Hosting Database Credentials

- **cPanel** - Look in "Databases" or "MySQL Databases"
- **Plesk** - Look in "Databases" section
- **WHM** - Check the database server information
- **Contact Support** - Ask your hosting provider for the database host, username, password, and database name

## Important Security Notes

- **Never** commit config/config.local.php to version control
- It's already in .gitignore to prevent accidental commits
- Keep these credentials private and secure
- Use strong passwords for your database

## Troubleshooting

If you still get a 500 error after setting up config.local.php:

1. Check the hosting server's PHP error logs
2. Verify database credentials are correct
3. Check if the database exists on the server
4. Ensure the database user has proper permissions
5. Contact your hosting provider's support
