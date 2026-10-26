# Java 26.3 terrain implementation and evidence

The live `terrain` generator still uses this project's existing algorithm. The native classes in `src/World/Generation` are tested building blocks for replacing it with Java 26.3 generation. They are not yet connected to chunk generation and do not change existing saves.

`tools/update_reference.py` exports `reference/26.3/worldgen-noise.json` by invoking the actual classes in the checksum-pinned official server JAR. Run with Python 3.11+ and a HotSpot Java 25 JDK (`JAVA_HOME=/path/to/jdk python3 tools/update_reference.py`). The reference tool needs the HotSpot diagnostic `_dlog` intrinsic switch described below. PHP needs no Java process, FFI or Java extension to run these classes.

The PHP checks run in `php tests/worldgen-noise.php` and in both the unit and full suites. They compare raw IEEE float/double bits or exact integer outputs, including:

- Signed 64-bit wrapping arithmetic, the legacy 48-bit LCG, xoroshiro128++ and seed upgrading. Products use integer limbs to avoid PHP's conversion to floating point on overflow.
- Bounded random rejection, interleaved uniform samples, Gaussian caching, forked streams, coordinate seeds, UTF-16 string hashes, named MD5 positional seeds and reseeding. Seeds include signed 64-bit extrema and negative values.
- Java 26.3 scalar Perlin sampling and float32 interpolation, with random consumption taken from the official implementation. Samples include negative coordinates, world edges and coordinate wrapping boundaries.
- Modern NormalNoise scalar 2D/3D sampling for all 64 built-in noise definitions, plus sparse/zero amplitudes, all normalization modes and octave/amplitude limits. Each definition is tested with both random sources and three seeds. Additional fixtures check the official parameter codec's range rules and optional null defaults.

Gaussian samples deserve a specific qualification: the default JVM's `Math.log` intrinsic and the same pinned Java code with only that intrinsic disabled yield different final bits for some samples. The exporter records both real observations. PHP uses the permitted fdlibm logarithm in `StableMath` and matches the disabled-intrinsic observations exactly; its difference from the default observations must stay within two double ULPs. The checked-in fixtures currently contain 17 such differences. This is not a claim of bit-identical Gaussian output on every JVM. Every other exported field must match across both JVM runs; the exporter fails otherwise. See `NOTICE.md` for the fdlibm notice.

Still required for terrain parity: the full density-function graph and interpolation, climate/biome selection, aquifers, material/surface rules, carvers, features and structures, Nether and End generation, and comparison of actual generated chunks against Java. Legacy Nether biome sampling and derivative/bulk noise APIs are not implemented by these scalar classes. Existing seed-range settings and world storage also require a separately tested migration before exposing vanilla generation. Issues #25, #41, #46, #47 and #49 remain open.
