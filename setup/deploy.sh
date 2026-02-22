#!/bin/bash

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}==================================${NC}"
echo -e "${BLUE}Yealink Config Builder - Deployment${NC}"
echo -e "${BLUE}==================================${NC}"
echo ""

# Get current directory
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

echo -e "${YELLOW}Project root: $PROJECT_ROOT${NC}"
echo ""

# Step 1: Git pull
echo -e "${BLUE}Step 1: Pulling latest code from GitHub...${NC}"
cd "$PROJECT_ROOT"
git pull origin main

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ Git pull successful!${NC}"
else
    echo -e "${RED}✗ Git pull failed!${NC}"
    exit 1
fi
echo ""

# Step 2: Check if migrations exist
echo -e "${BLUE}Step 2: Checking for database migrations...${NC}"

if [ -f "$PROJECT_ROOT/setup/run_migrations.php" ]; then
    echo -e "${GREEN}✓ Migration runner found${NC}"
    
    # Run migrations
    echo -e "${YELLOW}Running database migrations...${NC}"
    php "$PROJECT_ROOT/setup/run_migrations.php"
    
    if [ $? -eq 0 ]; then
        echo -e "${GREEN}✓ Migrations completed!${NC}"
    else
        echo -e "${RED}✗ Migration errors occurred (check output above)${NC}"
        exit 1
    fi
else
    echo -e "${YELLOW}⚠ No migration runner found, skipping...${NC}"
fi
echo ""

# Step 3: Set permissions
echo -e "${BLUE}Step 3: Setting file permissions...${NC}"
chmod -R 755 "$PROJECT_ROOT"
chmod -R 777 "$PROJECT_ROOT/uploads" 2>/dev/null || echo "uploads directory not found"
chmod -R 777 "$PROJECT_ROOT/logs" 2>/dev/null || echo "logs directory not found"
echo -e "${GREEN}✓ Permissions set${NC}"
echo ""

# Step 4: Clear cache (if applicable)
echo -e "${BLUE}Step 4: Clearing cache...${NC}"
if [ -d "$PROJECT_ROOT/cache" ]; then
    rm -rf "$PROJECT_ROOT/cache/*"
    echo -e "${GREEN}✓ Cache cleared${NC}"
else
    echo -e "${YELLOW}⚠ No cache directory found${NC}"
fi
echo ""

# Done
echo -e "${GREEN}==================================${NC}"
echo -e "${GREEN}Deployment completed successfully!${NC}"
echo -e "${GREEN}==================================${NC}"
echo ""
echo -e "${BLUE}Summary:${NC}"
echo -e "  • Code updated from GitHub"
echo -e "  • Database migrations applied"
echo -e "  • File permissions set"
echo -e "  • Cache cleared"
echo ""
echo -e "${YELLOW}Next steps:${NC}"
echo -e "  • Test the application: ${BLUE}https://yealink-cfg.eu/${NC}"
echo -e "  • Check logs if any issues occur"
echo ""