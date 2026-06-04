ALTER TABLE ChatMessages
    DROP COLUMN attachment_url,
    ADD COLUMN attachment_type VARCHAR(50) DEFAULT NULL AFTER body,
    ADD COLUMN attachment_body LONGTEXT DEFAULT NULL AFTER attachment_type;
