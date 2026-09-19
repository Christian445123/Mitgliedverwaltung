-- Reparatur: Fremdschlüssel der Teiltabellen zeigen fälschlich auf "members_backup_csharp"
-- (passiert, wenn schema.sql VOR dem RENAME TABLE eingespielt wurde) und müssen auf die
-- neue Tabelle "members" zeigen.
--
-- Hinweis MySQL: DROP FOREIGN KEY und ADD CONSTRAINT mit gleichem Namen müssen in
-- getrennten ALTER-Befehlen stehen (sonst Fehler 1826 "Duplicate foreign key constraint name").
--
-- Das Skript kann bedenkenlos mehrfach ausgeführt werden.
-- Die Teiltabellen sind zu diesem Zeitpunkt leer bzw. enthalten nur Kopien aus der Migration.

DELETE FROM member_access;
DELETE FROM member_equipment;
DELETE FROM member_addresses;
DELETE FROM member_documents;
DELETE FROM member_consents;
DELETE FROM member_guardians;
DELETE FROM member_camps;

ALTER TABLE member_camps DROP FOREIGN KEY fk_member_camps_member;
ALTER TABLE member_camps ADD CONSTRAINT fk_member_camps_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE CASCADE;

ALTER TABLE member_guardians DROP FOREIGN KEY fk_member_guardians_member;
ALTER TABLE member_guardians ADD CONSTRAINT fk_member_guardians_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE CASCADE;

ALTER TABLE member_consents DROP FOREIGN KEY fk_member_consents_member;
ALTER TABLE member_consents ADD CONSTRAINT fk_member_consents_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE CASCADE;

ALTER TABLE member_documents DROP FOREIGN KEY fk_member_documents_member;
ALTER TABLE member_documents ADD CONSTRAINT fk_member_documents_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE CASCADE;

ALTER TABLE member_addresses DROP FOREIGN KEY fk_member_addresses_member;
ALTER TABLE member_addresses ADD CONSTRAINT fk_member_addresses_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE CASCADE;

ALTER TABLE member_equipment DROP FOREIGN KEY fk_member_equipment_member;
ALTER TABLE member_equipment ADD CONSTRAINT fk_member_equipment_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE CASCADE;

ALTER TABLE member_access DROP FOREIGN KEY fk_member_access_member;
ALTER TABLE member_access ADD CONSTRAINT fk_member_access_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE CASCADE;
