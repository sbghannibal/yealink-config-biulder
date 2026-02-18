#!/bin/bash
# Yealink Config Builder - Installation Script
# Author: sbghannibal
# Version: 1.0

set -e

echo "=========================================="
echo "  Yealink Config Builder - Installer"
echo "=========================================="
echo ""

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Check if running as root
if [ "$EUID" -eq 0 ]; then 
   echo -e "${RED}⚠️  Don't run this script as root!${NC}"
   echo "Run as the web server user (e.g., www-data, admin)"
   exit 1
fi

# Step 1: Check requirements
echo -e "${YELLOW}[1/7]${NC} Checking requirements..."

command -v php >/dev/null 2>&1 || { echo -e "${RED}❌ PHP is not installed${NC}"; exit 1; }
command -v mysql >/dev/null 2>&1 || { echo -e "${RED}❌ MySQL client is not installed${NC}"; exit 1; }

PHP_VERSION=$(php -r "echo PHP_VERSION;" 2>/dev/null)
echo -e "${GREEN}✓${NC} PHP version: $PHP_VERSION"

# Check PHP extensions
php -m | grep -q pdo_mysql || { echo -e "${RED}❌ PHP PDO MySQL extension is not installed${NC}"; exit 1; }
php -m | grep -q mbstring || { echo -e "${RED}❌ PHP mbstring extension is not installed${NC}"; exit 1; }
echo -e "${GREEN}✓${NC} Required PHP extensions found"

# Step 2: Collect database credentials
echo ""
echo -e "${YELLOW}[2/7]${NC} Database Configuration"
echo "Please provide your MySQL database credentials:"
echo ""

read -p "Database host [localhost]: " DB_HOST
DB_HOST=${DB_HOST:-localhost}

read -p "Database name: " DB_NAME
while [ -z "$DB_NAME" ]; do
    echo -e "${RED}Database name cannot be empty${NC}"
    read -p "Database name: " DB_NAME
done

read -p "Database user: " DB_USER
while [ -z "$DB_USER" ]; do
    echo -e "${RED}Database user cannot be empty${NC}"
    read -p "Database user: " DB_USER
done

read -sp "Database password: " DB_PASS
echo ""
while [ -z "$DB_PASS" ]; do
    echo -e "${RED}Database password cannot be empty${NC}"
    read -sp "Database password: " DB_PASS
    echo ""
done

# Test database connection
echo ""
echo -e "${YELLOW}[3/7]${NC} Testing database connection..."
if mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" -e "USE $DB_NAME" 2>/dev/null; then
    echo -e "${GREEN}✓${NC} Database connection successful"
else
    echo -e "${RED}❌ Cannot connect to database${NC}"
    echo "Please check your credentials and try again"
    exit 1
fi

# Step 3: Create .env file
echo ""
echo -e "${YELLOW}[4/7]${NC} Creating .env configuration file..."

cat > .env << ENV_EOF
# Database Configuration
DB_HOST=$DB_HOST
DB_NAME=$DB_NAME
DB_USER=$DB_USER
DB_PASS=$DB_PASS
ENV_EOF

chmod 600 .env
echo -e "${GREEN}✓${NC} .env file created"

# Step 4: Run migrations
echo ""
echo -e "${YELLOW}[5/7]${NC} Running database migrations..."

MIGRATION_COUNT=0
for migration in migrations/*.sql; do
    if [ -f "$migration" ]; then
        echo "  → Running $(basename $migration)..."
        mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$migration" 2>&1 | grep -v "Using a password on the command line"
        ((MIGRATION_COUNT++))
    fi
done

echo -e "${GREEN}✓${NC} Ran $MIGRATION_COUNT migrations"

# Step 5: Seed database (optional)
echo ""
echo -e "${YELLOW}[6/7]${NC} Database Seeding"
read -p "Do you want to add sample data (admin user, device types, etc.)? [Y/n]: " SEED_DB
SEED_DB=${SEED_DB:-Y}

if [[ "$SEED_DB" =~ ^[Yy]$ ]]; then
    echo "  → Running seed.php..."
    php seed.php
    echo -e "${GREEN}✓${NC} Sample data added"
    echo ""
    echo -e "${YELLOW}📝 Default admin credentials:${NC}"
    echo "   Username: admin"
    echo "   Password: admin123"
    echo -e "${RED}   ⚠️  CHANGE THIS PASSWORD IMMEDIATELY AFTER FIRST LOGIN!${NC}"
else
    echo "Skipping database seeding"
    echo -e "${YELLOW}⚠️  You will need to manually create an admin user${NC}"
fi

# Step 6: Set permissions
echo ""
echo -e "${YELLOW}[7/7]${NC} Setting file permissions..."

# Find the web server user
if id "www-data" &>/dev/null; then
    WEB_USER="www-data"
elif id "apache" &>/dev/null; then
    WEB_USER="apache"
elif id "nginx" &>/dev/null; then
    WEB_USER="nginx"
else
    WEB_USER=$(whoami)
fi

echo "  → Web server user detected: $WEB_USER"

# Set ownership (if current user has sudo)
if sudo -n true 2>/dev/null; then
    sudo chown -R $WEB_USER:$WEB_USER .
    echo -e "${GREEN}✓${NC} Ownership set to $WEB_USER"
else
    echo -e "${YELLOW}⚠️  Cannot set ownership (no sudo access)${NC}"
    echo "   Please manually run: sudo chown -R $WEB_USER:$WEB_USER $(pwd)"
fi

# Set directory permissions
chmod -R 755 .
chmod 600 .env
chmod 644 settings/*.php
chmod 755 provision/

echo -e "${GREEN}✓${NC} Permissions set"

# Installation complete
echo ""
echo "=========================================="
echo -e "${GREEN}✓ Installation Complete!${NC}"
echo "=========================================="
echo ""
echo "Next steps:"
echo "1. Configure your web server (Apache/Nginx) to point to $(pwd)"
echo "2. Access the application at: https://your-domain.com"
echo "3. Login with the admin credentials"
echo "4. CHANGE THE DEFAULT PASSWORD!"
echo ""
echo "Documentation:"
echo "  → README.md - General information"
echo "  → INSTALL.md - Detailed installation guide"
echo ""
echo "For provisioning setup, configure Yealink devices to:"
echo "  Server URL: https://your-domain.com/provision/\$MAC.cfg"
echo ""
echo "Need help? Check the documentation or open an issue on GitHub."
echo ""
