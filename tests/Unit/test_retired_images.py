import importlib.util
import json
from pathlib import Path
import stat
import tempfile
from types import SimpleNamespace
import unittest

spec=importlib.util.spec_from_file_location('cleanup',Path(__file__).resolve().parents[2]/'tools/remove_old_pictures.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)

class CleanupTests(unittest.TestCase):
    def test_symlinks_are_never_traversed(self):
        visited=[]
        class Sftp:
            def listdir_attr(self,path):
                visited.append(path)
                if path==m.ROOT:
                    return [SimpleNamespace(filename='outside',st_mode=stat.S_IFLNK),SimpleNamespace(filename='nested',st_mode=stat.S_IFDIR)]
                return [SimpleNamespace(filename='photo.jpg',st_mode=stat.S_IFREG)]
        files,directories=m.collect(Sftp(),m.ROOT)
        self.assertEqual(visited,[m.ROOT,m.ROOT+'/nested'])
        self.assertIn(m.ROOT+'/outside',files)
        self.assertEqual(directories,[m.ROOT+'/nested'])
    def test_old_or_incomplete_manifest_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            file=Path(directory)/'manifest.json'
            for products in [[],[{'images':['/products_pictures/test.jpg']}]]:
                file.write_text(json.dumps(products))
                with self.assertRaises(ValueError):m.validate_manifest(file)
    def test_parent_entries_rejected(self):
        class Sftp:
            def listdir_attr(self,path):return [SimpleNamespace(filename='..',st_mode=stat.S_IFDIR)]
        with self.assertRaises(ValueError):m.collect(Sftp(),m.ROOT)
