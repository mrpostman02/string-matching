# String-Matching Experiment Program

A local PHP web application for comparing **Naive**, **Knuth-Morris-Pratt (KMP)**, and **Aho-Corasick** string-matching algorithms.

The program generates Random and Narrative datasets, runs the algorithms on the same inputs, validates matching positions, and exports execution times and summary statistics. It also supports manual inputs and resuming interrupted batch experiments.

## Requirements

- PHP **8.2 or newer**, **64-bit**, available as `php` in your terminal.
- PHP's standard `random`, `json`, `pcre`, `session`, and `iconv` extensions.
- `proc_open` enabled so the application can start its background PHP worker.
- Write permission for this project folder to save datasets and results.
- Windows PowerShell for background worker launching on Windows; a POSIX shell with `nohup` on Linux/macOS.

No database, Composer packages, or frontend build is required.

## Run the program

**Using Git Bash** (also works in a Linux/macOS terminal):

```bash
php -v
mkdir -p experiment-results/tmp
php -d upload_tmp_dir=experiment-results/tmp -S 127.0.0.1:8080 -t public
```

**Using Windows PowerShell:**

```powershell
php -v
New-Item -ItemType Directory -Path experiment-results/tmp -Force | Out-Null
php -d upload_tmp_dir=experiment-results/tmp -S 127.0.0.1:8080 -t public
```

`php -v` should show PHP 8.2 or newer. If `php` is not found, add your PHP installation folder to your system's PATH and reopen the terminal.

Open **http://127.0.0.1:8080** in your browser. Keep the terminal open while using the program. Press **Ctrl+C** to stop the web server. To run it again, open the project folder and repeat the startup command.

Use `public` as the web document root. The application creates its dataset and result folders under `experiment-results` automatically.

## Use the program

1. **Generate Datasets:** Click **Generate Input Matrix** to prepare 36 input configurations. The matrix uses Random and Narrative text, text lengths of 50/500/5,000, pattern lengths of 3/8/15, and one or three patterns. The default dataset seed is 755; use **Generate a different dataset** to change it, for example to 756.
2. **Export:** Preview the generated inputs and click **Export Full CSV**.
3. **Upload & Run:** Upload that CSV, review the planned measurements, check the confirmation box, and click **Run All Experiments**. Each algorithm uses two warm-ups and 30 measured repetitions per configuration, giving 3,240 measured runs for a complete matrix.
4. **Results:** Follow the progress and download **Results CSV** or **Summary CSV**. Use **Resume Experiments** if a batch stops before completion.

For a quick check, open **Manual Test**, enter the text and one pattern per line, choose an algorithm and run count, then click **Run Experiment** or **Compare All Three**.

The bundled Alice narrative source is in `datasets/alice_in_wonderland.txt`, including its source notice and license. Saved datasets are stored in `experiment-results/datasets`, and experiment results are stored in `experiment-results/sessions`. Generated files remain available between launches.
