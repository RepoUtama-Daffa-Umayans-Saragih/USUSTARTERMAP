SELECT 'CREATE DATABASE usuclassmap_test'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'usuclassmap_test')\gexec
