-- SQL script to create config_versions and config_download_history tables

CREATE TABLE config_versions (
    id SERIAL PRIMARY KEY,
    version_number VARCHAR(255) NOT NULL,
    release_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    description TEXT
);

CREATE TABLE config_download_history (
    id SERIAL PRIMARY KEY,
    version_id INT REFERENCES config_versions(id),
    download_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    user_ip VARCHAR(255)
);