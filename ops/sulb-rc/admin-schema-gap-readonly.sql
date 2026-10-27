-- Run SELECT statements only using an EXISTING administrator in CloudPanel.
-- Do not grant TRIGGER to sulbmetadata: that privilege also permits trigger DDL.
-- No passwords, trigger bodies, definers, defaults or business rows are exported.
SELECT VERSION() AS server_version, @@version_comment AS distribution;
SELECT COUNT(*) AS visible_trigger_count
FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='sulbprod';
SELECT EVENT_OBJECT_TABLE, TRIGGER_NAME, EVENT_MANIPULATION, ACTION_TIMING,
       ACTION_ORDER, SQL_MODE, SHA2(ACTION_STATEMENT,256) AS body_sha256
FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='sulbprod'
ORDER BY EVENT_OBJECT_TABLE,ACTION_TIMING,EVENT_MANIPULATION,ACTION_ORDER,TRIGGER_NAME;
SELECT TABLE_NAME,COLUMN_NAME,CHARACTER_SET_NAME,COLLATION_NAME
FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='sulbprod'
AND CHARACTER_SET_NAME IS NOT NULL ORDER BY TABLE_NAME,ORDINAL_POSITION;
