#!/bin/sh
# assets/src/*.css dosyalarını sırayla assets/site.css içinde birleştirir.
cd "$(dirname "$0")" && cat [0-9]*.css > ../site.css
