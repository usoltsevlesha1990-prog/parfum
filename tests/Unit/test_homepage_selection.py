import json
from pathlib import Path
import unittest
ROOT=Path(__file__).resolve().parents[2]
class HomepageSelectionTests(unittest.TestCase):
    def test_owner_selected_top_and_available_chance_line(self):
        ids=json.loads((ROOT/'resources/catalog/homepage.json').read_text())['featured_ids']
        products={int(p['id']):p for p in json.loads((ROOT/'tests/fixtures/catalog.json').read_text())['products']}
        images=json.loads((ROOT/'resources/catalog/image-selection.json').read_text())
        self.assertEqual(ids,[25,73,57,74,7,41,17,75,8,2,10,13,59])
        self.assertEqual(len(set(ids)),13)
        self.assertGreaterEqual(len({products[id]['brand_id'] for id in ids}),6)
        self.assertEqual([id for id in ids if not images[str(id)]['bottle']],[73,74,75])
