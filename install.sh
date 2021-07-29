#!/bin/bash

FILE=.env
if [[ -f "$FILE" ]]; then
    echo "$FILE exists. Exiting."
    exit 0;
fi

echo
echo "Starting Installation"
echo "--------------------------"
echo
echo "Application Name:"
read AppName
echo

echo "Database Name:"
read DbName
echo

echo "Database Username [root]:"
read DbUsername
echo

if [ -z "$DbUsername" ]
then
    DbUsername=root
fi

echo "Database Password:"
read DbPassword
echo

echo "Locale [en]:"
read AppLocale
echo

if [ -z "$AppLocale" ]
then
    AppLocale=en
fi

read -p "Enable social login? [y/N]:" -n 1 EnableSocialLogin
echo
if [[ ! $EnableSocialLogin =~ ^[Yy]$ ]]
then
    EnableSocialLogin=false
else
    EnableSocialLogin=true
fi
echo

read -p "Enable debugbar? [Y/n]:" -n 1 EnableDebugbar
echo
if [[ ! $EnableDebugbar =~ ^[Nn]$ ]]
then
    EnableDebugbar=true
else
    EnableDebugbar=false
fi

echo 'Input settings:'
echo
echo "Application Name: ${AppName}"
echo "Application Locale: ${AppLocale}"
echo "Database Name: ${DbName}"
echo "Database Username: ${DbUsername}"
echo "Database Password: ${DbPassword}"
echo "Enable Social Logins: ${EnableSocialLogin}"
echo "Enable Debugbar: ${EnableDebugbar}"
echo "--------------------------"
echo

read -p "All set? [y/N]:" -n 1 DoContinue
echo
if [[ ! $DoContinue =~ ^[Yy]$ ]]
then
    echo 'Bye!'
    exit 0
fi
echo

php composer.phar install

cp .env.example .env

sed -i "s/APP_NAME=\"BaseBackend\"/APP_NAME=\"$AppName\"/g" .env
sed -i "s/APP_LOCALE=en/APP_LOCALE=$AppLocale/g" .env
sed -i "s/DB_DATABASE=laravel/DB_DATABASE=$DbName/g" .env
sed -i "s/DB_USERNAME=root/DB_USERNAME=$DbUsername/g" .env
sed -i "s/DB_PASSWORD=root/DB_PASSWORD=\"$DbPassword\"/g" .env
sed -i "s/DEBUGBAR_ENABLED=true/DEBUGBAR_ENABLED=$EnableDebugbar/g" .env
sed -i "s/SOCIALITE_ENABLED=false/SOCIALITE_ENABLED=$EnableSocialLogin/g" .env
sed -i "s/GOOGLE_LOGIN_ENABLED=false/GOOGLE_LOGIN_ENABLED=$EnableSocialLogin/g" .env
sed -i "s/GITHUB_LOGIN_ENABLED=false/GITHUB_LOGIN_ENABLED=$EnableSocialLogin/g" .env

php artisan key:generate

php artisan storage:link

php artisan migrate --seed

echo
echo
echo "----------------------"
echo "Installation complete!"
echo "----------------------"

php artisan inspire
