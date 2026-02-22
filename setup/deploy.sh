#!/bin/bash

##############################################
# Yealink Config Builder - Deployment Script
##############################################

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Project directory
PROJECT_DIR="/home/admin/domains/yealink-cfg.eu/public_html"

echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}Yealink Config Builder - Deployment${NC}"
echo -e "${BLUE}========================================${NC}\n"

# Navigate to project directory
if [ ! -d "$PROJECT_DIR" ]; then
    echo -e "${RED}✗ Project directory not found: $PROJECT_DIR${NC}"
    exit 1
fi

cd "$PROJECT_DIR" || exit 1
echo -e "${GREEN}✓ Changed to project directory${NC}\n"

# Check if git is initialized
if [ ! -d ".git" ]; then
    echo -e "${RED}✗ Not a git repository${NC}"
    exit 1
fi

# Pull latest code
echo -e "${YELLOW}➜ Pulling latest code from GitHub...${NC}"
git fetch origin
git pull origin main

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ Code pulled successfully${NC}\n"
else
    echo -e "${RED}✗ Failed to pull code${NC}"
    exit 1
fi

# Run database migrations
echo -e "${YELLOW}➜ Running database migrations...${NC}"
php setup/run_migrations.php

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ Migrations completed${NC}\n"
else
    echo -e "${RED}✗ Migrations failed${NC}"
    exit 1
fi

# Create required directories if they don't exist
echo -e "${YELLOW}➜ Creating required directories...${NC}"
mkdir -p translations
mkdir -p setup/migrations
mkdir -p logs
echo -e "${GREEN}✓ Directories created${NC}\n"

# Set correct permissions
echo -e "${YELLOW}➜ Setting permissions...${NC}"
chmod 755 setup/*.php setup/*.sh 2>/dev/null
chmod 755 -R translations 2>/dev/null
chmod 755 -R setup/migrations 2>/dev/null
chmod 755 -R logs 2>/dev/null
echo -e "${GREEN}✓ Permissions set${NC}\n"

# Clear PHP OPcache if available
echo -e "${YELLOW}➜ Clearing PHP cache...${NC}"
if command -v php &> /dev/null; then
    php -r "if (function_exists('opcache_reset')) { opcache_reset(); echo 'OPcache cleared\n'; } else { echo 'OPcache not available\n'; }"
fi
echo -e "${GREEN}✓ Cache cleared${NC}\n"

# Show current git status
echo -e "${YELLOW}➜ Current version:${NC}"
git log -1 --oneline
echo ""

echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}Deployment completed successfully!${NC}"
echo -e "${GREEN}========================================${NC}"