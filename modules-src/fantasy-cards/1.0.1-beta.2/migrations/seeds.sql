INSERT INTO card_sets (uuid, slug, name, description, cover_image, is_active, available_in_free_packs, sort_order)
SELECT '10000000-0000-4000-8000-000000000001', 'erste-aera', 'Erste Ära', 'Ein erstes Demo-Set für das Fantasy-Cards-Fundament.', '', 1, 1, 10
WHERE NOT EXISTS (SELECT 1 FROM card_sets WHERE slug = 'erste-aera');

INSERT INTO cards (uuid, set_id, card_number, slug, name, description, rarity, faction, element_name, image_path, thumbnail_path, status, is_active, available_in_boosters, sort_order)
SELECT '10000000-0000-4000-8000-000000000101', s.id, 'EA-001', 'waldhueterin', 'Waldhüterin', 'Eine ruhige Wächterin der alten Pfade.', 'common', 'Hainwacht', 'Natur', '', '', 'active', 1, 1, 10
FROM card_sets s
WHERE s.slug = 'erste-aera'
  AND NOT EXISTS (SELECT 1 FROM cards c WHERE c.set_id = s.id AND c.slug = 'waldhueterin');

INSERT INTO cards (uuid, set_id, card_number, slug, name, description, rarity, faction, element_name, image_path, thumbnail_path, status, is_active, available_in_boosters, sort_order)
SELECT '10000000-0000-4000-8000-000000000102', s.id, 'EA-002', 'mondklinge', 'Mondklinge', 'Eine seltene Klinge, deren Licht nur bei Nacht erwacht.', 'rare', 'Silberzirkel', 'Mond', '', '', 'active', 1, 1, 20
FROM card_sets s
WHERE s.slug = 'erste-aera'
  AND NOT EXISTS (SELECT 1 FROM cards c WHERE c.set_id = s.id AND c.slug = 'mondklinge');

INSERT INTO cards (uuid, set_id, card_number, slug, name, description, rarity, faction, element_name, image_path, thumbnail_path, status, is_active, available_in_boosters, sort_order)
SELECT '10000000-0000-4000-8000-000000000103', s.id, 'EA-003', 'drachenorakel', 'Drachenorakel', 'Ein mythisches Wesen, das kommende Zeitalter in Asche liest.', 'mythic', 'Aschenbund', 'Feuer', '', '', 'active', 1, 1, 30
FROM card_sets s
WHERE s.slug = 'erste-aera'
  AND NOT EXISTS (SELECT 1 FROM cards c WHERE c.set_id = s.id AND c.slug = 'drachenorakel');
