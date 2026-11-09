@echo off
setlocal
set "PSQL="
for /d %%D in ("C:\Program Files\PostgreSQL\*") do if exist "%%D\bin\psql.exe" set "PSQL=%%D\bin\psql.exe"
if "%PSQL%"=="" (
  echo Could not find psql.exe under C:\Program Files\PostgreSQL. Is PostgreSQL installed?
  pause & exit /b 1
)
echo Using %PSQL%
echo.
echo Enter the password of the postgres user when asked.
"%PSQL%" -U postgres -d safety_store -f "C:\xampp\htdocs\safety-store\database\restrict_postgres.sql"
echo.
echo ---- Testing the store_app login ----
set PGPASSWORD=daxyray111@gmail.com352535
"%PSQL%" -h localhost -U store_app -d safety_store -c "SELECT count(*) AS products FROM products;"
echo.
echo If you see a number above, the database is fixed. Reload the shop page.
pause
