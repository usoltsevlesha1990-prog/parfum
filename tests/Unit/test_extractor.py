import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location('extractor', Path(__file__).resolve().parents[2] / 'tools/extract_catalog.py')
extractor = importlib.util.module_from_spec(spec)
spec.loader.exec_module(extractor)


class ExtractorTests(unittest.TestCase):
    def test_literals_preserve_decimal_and_escapes(self):
        rows = extractor.parse_values("(1,'L\\'Artisan',NULL,'line\\nnext',1990.00),\n(2,'it''s',0,'a;b',0.10);", ['id', 'name', 'nullable', 'text', 'price'])
        self.assertEqual(rows[0], {'id': '1', 'name': "L'Artisan", 'nullable': None, 'text': 'line\nnext', 'price': '1990.00'})
        self.assertEqual(rows[1]['name'], "it's")
        self.assertEqual(rows[1]['text'], 'a;b')
        self.assertEqual(rows[1]['price'], '0.10')

    def test_reject_expressions_and_wrong_column_counts(self):
        for values in ["(SLEEP(1));", "(LOAD_FILE('/etc/passwd'));", '(1,2);', '(1', '(NULL,);']:
            with self.assertRaises(ValueError):
                extractor.parse_values(values, ['id'])

    def test_schema_is_required(self):
        with self.assertRaises(ValueError):
            extractor.extract('DROP DATABASE production;')


if __name__ == '__main__':
    unittest.main()
