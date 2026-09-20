INSERT INTO pages_entries (title, slug, content_markdown, visibility, menu_group, show_in_header, show_in_footer, is_active, sort_order)
VALUES
    (
        'Impressum',
        'impressum',
        '## Impressum

Bitte trage hier die rechtlich erforderlichen Angaben deiner Instanz ein.',
        'public',
        'Rechtliches',
        0,
        1,
        1,
        10
    ),
    (
        'Datenschutz',
        'datenschutz',
        '## Datenschutz

Bitte trage hier deine Datenschutzhinweise ein.',
        'public',
        'Rechtliches',
        0,
        1,
        1,
        20
    )
ON DUPLICATE KEY UPDATE
    title = VALUES(title),
    visibility = VALUES(visibility),
    menu_group = VALUES(menu_group),
    show_in_header = VALUES(show_in_header),
    show_in_footer = VALUES(show_in_footer),
    is_active = VALUES(is_active);
