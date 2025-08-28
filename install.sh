#!/bin/bash

echo "Installing Laravel AppSync Broadcaster package..."

# Install the package
composer install

echo "Package installed successfully!"
echo ""
echo "Next steps:"
echo "1. Publish the config file: php artisan vendor:publish --tag=appsync-config"
echo "2. Update your .env file with AppSync credentials"
echo "3. Update config/broadcasting.php to include the AppSync connection"
echo "4. Set BROADCAST_DRIVER=appsync in your .env file"
echo ""
echo "For detailed setup instructions, please see README.md"
