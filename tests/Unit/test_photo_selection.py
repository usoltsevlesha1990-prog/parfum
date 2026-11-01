import json
from pathlib import Path
import re
import unittest
ROOT=Path(__file__).resolve().parents[2]

class PhotoSelectionTests(unittest.TestCase):
    def test_selection_is_complete_ordered_and_bounded(self):
        data=json.loads((ROOT/'resources/catalog/image-selection.json').read_text())
        products=json.loads((ROOT/'tests/fixtures/catalog.json').read_text())['products']
        self.assertEqual(set(data),{str(p['id']) for p in products})
        counts={'bottle':0,'bottle_box':0}
        for roles in data.values():
            self.assertEqual(list(roles),['bottle','bottle_box'])
            for role,file in roles.items():
                if file is not None:
                    self.assertRegex(file,r'^[1-9][0-9]*-[a-f0-9]{16}\.jpg$')
                    counts[role]+=1
            if roles['bottle_box']:
                self.assertTrue(roles['bottle'])
                self.assertNotEqual(roles['bottle'],roles['bottle_box'])
        self.assertEqual(counts,{'bottle':60,'bottle_box':57})
        for id in ['60','62','63']:self.assertIsNone(data[id]['bottle_box'])
        for id in ['73','74','75']:self.assertEqual(data[id],{'bottle':None,'bottle_box':None})
