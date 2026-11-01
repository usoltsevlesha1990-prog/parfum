import importlib.util
import io
from pathlib import Path
import unittest
from PIL import Image

spec=importlib.util.spec_from_file_location('image_migration',Path(__file__).resolve().parents[2]/'tools/reorganize_images.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)

class MigrationTests(unittest.TestCase):
    def test_content_based_names_are_stable(self):
        self.assertEqual(m.filename(1,b'abc'),'1-ba7816bf8f01cfea.jpg')
        self.assertNotEqual(m.filename(2,b'abc'),m.filename(1,b'abc'))
        self.assertNotEqual(m.filename(1,b'abc'),m.filename(1,b'def'))
    def test_existing_jpeg_is_not_reencoded(self):
        data=(Path(__file__).resolve().parents[1]/'fixtures/pixel.jpg').read_bytes()
        self.assertEqual(m.jpeg_bytes(data),data)
    def test_transparent_png_becomes_valid_white_background_jpeg(self):
        buffer=io.BytesIO();Image.new('RGBA',(2,2),(255,0,0,0)).save(buffer,'PNG')
        result=m.jpeg_bytes(buffer.getvalue())
        with Image.open(io.BytesIO(result)) as image:
            self.assertEqual(image.format,'JPEG');self.assertEqual(image.size,(2,2))
            self.assertTrue(all(v>=250 for v in image.getpixel((0,0))))
    def test_external_origins_rejected(self):
        for value in ['https://example.com/x','//example.com/x']:
            with self.assertRaises(ValueError):m.public_url(value)
