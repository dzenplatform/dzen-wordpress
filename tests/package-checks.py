"""Exercise release packaging without touching the developer's working tree."""

import hashlib
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
import zipfile


SOURCE = Path(__file__).resolve().parent.parent


class PackageChecks(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        for name in ("dzen-chat.php", "uninstall.php", "readme.txt"):
            shutil.copyfile(SOURCE / name, self.root / name)
        for name in ("src", "assets", "bin", "languages"):
            shutil.copytree(SOURCE / name, self.root / name)
        self.git("init", "-q")
        self.git("config", "core.excludesFile", os.devnull)
        self.git("add", ".")
        self.version = self.run_package("--version").stdout.strip()
        self.archive = self.root / "dist" / f"dzen-chat-{self.version}.zip"

    def git(self, *args):
        subprocess.run(["git", *args], cwd=self.root, check=True, capture_output=True)

    def run_package(self, *args, success=True):
        result = subprocess.run(
            ["python3", "bin/package.py", *args], cwd=self.root,
            text=True, capture_output=True,
        )
        if success:
            self.assertEqual(result.returncode, 0, result.stderr)
        else:
            self.assertNotEqual(result.returncode, 0)
            self.assertFalse(self.archive.exists())
        return result

    def test_runtime_only_and_checksum(self):
        (self.root / "src" / "local-secret.php").write_text("not for distribution")
        (self.root / ".env").write_text("EXAMPLE_TOKEN=not-a-real-token")
        (self.root / "tests").mkdir()
        (self.root / "tests" / "fixture.php").write_text("fixture")
        self.git("add", "-f", ".env", "tests")
        self.run_package("--tag", f"v{self.version}")
        with zipfile.ZipFile(self.archive) as package:
            self.assertIsNone(package.testzip())
            names = package.namelist()
            self.assertIn("dzen-chat/dzen-chat.php", names)
            self.assertIn("dzen-chat/src/Connection.php", names)
            self.assertIn("dzen-chat/languages/dzen-chat.pot", names)
            self.assertIn("dzen-chat/languages/dzen-chat-ru_RU.po", names)
            self.assertIn("dzen-chat/languages/dzen-chat-ru_RU.mo", names)
            self.assertTrue(all(name.startswith("dzen-chat/") for name in names))
            self.assertFalse(any("secret" in name or "/tests/" in name or "/bin/" in name or ".env" in name for name in names))
            self.assertEqual(package.read("dzen-chat/dzen-chat.php"), (self.root / "dzen-chat.php").read_bytes())
        expected = f"{hashlib.sha256(self.archive.read_bytes()).hexdigest()}  {self.archive.name}\n"
        self.assertEqual(self.archive.with_suffix(".zip.sha256").read_text(), expected)

    def test_reproducible_with_different_mtime_and_permissions(self):
        self.run_package()
        first = self.archive.read_bytes()
        path = self.root / "dzen-chat.php"
        os.utime(path, (1234567890, 1234567890))
        path.chmod(0o755)
        self.run_package()
        self.assertEqual(first, self.archive.read_bytes())

    def test_wrong_tag_rejected(self):
        self.assertIn("Release tag must be", self.run_package("--tag", "v99.0.0", success=False).stderr)

    def test_mismatched_metadata_rejected(self):
        path = self.root / "readme.txt"
        path.write_text(path.read_text().replace(f"Stable tag: {self.version}", "Stable tag: 99.0.0"))
        self.assertIn("must match", self.run_package(success=False).stderr)

    def test_missing_changelog_rejected(self):
        path = self.root / "readme.txt"
        path.write_text(path.read_text().replace(f"= {self.version} =", "= 99.0.0 ="))
        self.assertIn("Missing changelog", self.run_package(success=False).stderr)

    def test_symlink_rejected(self):
        (self.root / "src" / "linked-secret.php").symlink_to("../readme.txt")
        self.git("add", "src/linked-secret.php")
        self.assertIn("regular file", self.run_package(success=False).stderr)

    def test_missing_translation_rejected(self):
        self.git("rm", "-f", "languages/dzen-chat-ru_RU.mo")
        self.assertIn("translation catalogs", self.run_package(success=False).stderr)


if __name__ == "__main__":
    unittest.main(verbosity=2)
