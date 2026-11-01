import json
from pathlib import Path
import unittest
ROOT=Path(__file__).resolve().parents[2]
class HomepageSelectionTests(unittest.TestCase):
    def test_ten_distinct_products_with_main_photos(self):
        ids=json.loads((ROOT/'resources/catalog/homepage.json').read_text())['featured_ids']
        products={int(p['id']):p for p in json.loads((ROOT/'tests/fixtures/catalog.json').read_text())['products']}
        images=json.loads((ROOT/'resources/catalog/image-selection.json').read_text())
        self.assertEqual(len(ids),10);self.assertEqual(len(set(ids)),10)
        self.assertGreaterEqual(len({products[id]['brand_id'] for id in ids}),6)
        self.assertTrue(all(images[str(id)]['bottle'] for id in ids))
