SET GLOBAL event_scheduler = ON;

DROP EVENT IF EXISTS cleanup_messages;

CREATE EVENT cleanup_messages
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_TIMESTAMP
DO
    DELETE m
    FROM ChatMessages m
    LEFT JOIN ChatRooms r ON m.chat_id = r.id AND m.chat_type = 'group'
    WHERE
        (m.chat_type = 'group' AND m.created_at < NOW() - INTERVAL COALESCE(r.ttl_days, {{DEFAULT_TTL_DAYS}}) DAY)
        OR (m.chat_type = 'direct' AND m.created_at < NOW() - INTERVAL {{DEFAULT_TTL_DAYS}} DAY);
