#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#ifdef _WIN32
#  define CSV_EXPORT __declspec(dllexport)
#else
#  define CSV_EXPORT __attribute__((visibility("default")))
#endif

#define INITIAL_BUFFER 256

/* ── StringArray ─────────────────────────────────────────────────────────── */

typedef struct {
    char **items;
    size_t count;
    size_t capacity;
} StringArray;

static void array_init(StringArray *array) {
    array->items    = NULL;
    array->count    = 0;
    array->capacity = 0;
}

static int array_push_owned(StringArray *array, char *value) {
    if (array->count == array->capacity) {
        size_t newCapacity = array->capacity == 0 ? 8 : array->capacity * 2;
        char **newItems = (char **)realloc(array->items, newCapacity * sizeof(char *));
        if (!newItems) return 0;
        array->items    = newItems;
        array->capacity = newCapacity;
    }
    array->items[array->count++] = value;
    return 1;
}

static char *string_duplicate(const char *text) {
    size_t length = strlen(text);
    char *copy = (char *)malloc(length + 1);
    if (!copy) return NULL;
    memcpy(copy, text, length + 1);
    return copy;
}

static void array_free_contents(StringArray *array) {
    size_t i;
    for (i = 0; i < array->count; i++) free(array->items[i]);
    free(array->items);
    array->items    = NULL;
    array->count    = 0;
    array->capacity = 0;
}

/* ── Cell buffer ─────────────────────────────────────────────────────────── */

static int append_char(char **buffer, size_t *length, size_t *capacity, char character) {
    if (*length + 1 >= *capacity) {
        size_t nc = (*capacity == 0) ? INITIAL_BUFFER : (*capacity * 2);
        char *nb  = (char *)realloc(*buffer, nc);
        if (!nb) return 0;
        *buffer   = nb;
        *capacity = nc;
    }
    (*buffer)[*length] = character;
    *length += 1;
    (*buffer)[*length] = '\0';
    return 1;
}

/* ── Writer (output abstraction) ─────────────────────────────────────────── */

typedef struct {
    char  *buf;   /* heap buffer (buffer mode only) */
    size_t len;
    size_t cap;
    FILE  *fp;    /* NULL → buffer mode, non-NULL → file mode */
    int    error;
} Writer;

static void writer_init_buf(Writer *w) {
    w->buf = NULL; w->len = 0; w->cap = 0; w->fp = NULL; w->error = 0;
}

static void writer_putchar(Writer *w, char c) {
    if (w->error) return;
    if (w->fp) {
        if (fputc((unsigned char)c, w->fp) == EOF) w->error = 1;
        return;
    }
    if (w->len + 2 > w->cap) {
        size_t nc = w->cap < 256 ? 4096 : w->cap * 2;
        char *nb  = (char *)realloc(w->buf, nc);
        if (!nb) { w->error = 1; return; }
        w->buf = nb;
        w->cap = nc;
    }
    w->buf[w->len++] = c;
    w->buf[w->len]   = '\0';
}

static void writer_puts(Writer *w, const char *s) {
    while (*s) writer_putchar(w, *s++);
}

static void writer_printf_ld(Writer *w, long val) {
    char tmp[32];
    snprintf(tmp, sizeof(tmp), "%ld", val);
    writer_puts(w, tmp);
}

static void writer_printf_04x(Writer *w, unsigned int val) {
    char tmp[16];
    snprintf(tmp, sizeof(tmp), "\\u%04x", val);
    writer_puts(w, tmp);
}

static void writer_free(Writer *w) {
    if (!w->fp) free(w->buf);
    w->buf = NULL; w->len = 0; w->cap = 0;
}

/* ── Output helpers ──────────────────────────────────────────────────────── */

static void write_json_escaped(Writer *w, const char *text) {
    const unsigned char *cursor = (const unsigned char *)text;
    writer_putchar(w, '"');
    while (*cursor) {
        unsigned char c = *cursor;
        if      (c == '"')  writer_puts(w, "\\\"");
        else if (c == '\\') writer_puts(w, "\\\\");
        else if (c == '\b') writer_puts(w, "\\b");
        else if (c == '\f') writer_puts(w, "\\f");
        else if (c == '\n') writer_puts(w, "\\n");
        else if (c == '\r') writer_puts(w, "\\r");
        else if (c == '\t') writer_puts(w, "\\t");
        else if (c < 32)    writer_printf_04x(w, c);
        else                writer_putchar(w, (char)c);
        cursor++;
    }
    writer_putchar(w, '"');
}

static int push_current_cell(StringArray *row, char *cellBuffer) {
    char *copy = string_duplicate(cellBuffer);
    if (!copy) return 0;
    return array_push_owned(row, copy);
}

static int row_is_empty(const StringArray *row) {
    size_t i;
    if (row->count == 0) return 1;
    for (i = 0; i < row->count; i++) {
        if (row->items[i][0] != '\0') return 0;
    }
    return 1;
}

static void write_headers(Writer *w, const StringArray *headers) {
    size_t i;
    writer_puts(w, "{\"headers\":[");
    for (i = 0; i < headers->count; i++) {
        if (i > 0) writer_putchar(w, ',');
        if (headers->items[i][0] == '\0') {
            char generated[64];
            snprintf(generated, sizeof(generated), "Columna %zu", i + 1);
            write_json_escaped(w, generated);
        } else {
            write_json_escaped(w, headers->items[i]);
        }
    }
    writer_puts(w, "],\"rows\":[");
}

static void write_row(Writer *w, const StringArray *row, size_t columnCount, int *isFirstRow) {
    size_t i;
    if (!*isFirstRow) writer_putchar(w, ',');
    *isFirstRow = 0;
    writer_putchar(w, '[');
    for (i = 0; i < columnCount; i++) {
        if (i > 0) writer_putchar(w, ',');
        write_json_escaped(w, i < row->count ? row->items[i] : "");
    }
    writer_putchar(w, ']');
}

static void write_ndjson_line(Writer *w, const StringArray *row, size_t columnCount) {
    size_t i;
    writer_putchar(w, '[');
    for (i = 0; i < columnCount; i++) {
        if (i > 0) writer_putchar(w, ',');
        write_json_escaped(w, i < row->count ? row->items[i] : "");
    }
    writer_puts(w, "]\n");
}

/* ── Core CSV processing ─────────────────────────────────────────────────── */

static int csv_process(FILE *file, long offset, long limit, int ndjsonMode, Writer *w) {
    int ch;
    int inQuotes       = 0;
    int headersPrinted = 0;
    int firstDataRow   = 1;
    int skipLf         = 0;
    int offsetMode     = 0;
    long rowCount      = 0;
    long nextOffset    = 0;
    int  hasMore       = 0;

    char   *cellBuffer   = NULL;
    size_t  cellLength   = 0;
    size_t  cellCapacity = 0;

    StringArray headers;
    StringArray row;
    array_init(&headers);
    array_init(&row);

    if (!append_char(&cellBuffer, &cellLength, &cellCapacity, '\0')) return 3;
    cellLength    = 0;
    cellBuffer[0] = '\0';

    if (!ndjsonMode && offset > 0) {
        offsetMode = 1;
        if (fseek(file, offset, SEEK_SET) != 0) {
            free(cellBuffer);
            return 4;
        }
        writer_puts(w, "{\"rows\":[");
        headersPrinted = 1;
    }

    while ((ch = fgetc(file)) != EOF) {
        char current = (char)ch;

        if (skipLf && current == '\n') { skipLf = 0; continue; }
        skipLf = 0;

        if (current == '"') {
            if (inQuotes) {
                int next = fgetc(file);
                if (next == '"') {
                    if (!append_char(&cellBuffer, &cellLength, &cellCapacity, '"')) {
                        free(cellBuffer);
                        array_free_contents(&headers);
                        array_free_contents(&row);
                        return 5;
                    }
                } else {
                    inQuotes = 0;
                    if (next != EOF) ungetc(next, file);
                }
            } else if (cellLength == 0) {
                inQuotes = 1;
            } else {
                if (!append_char(&cellBuffer, &cellLength, &cellCapacity, current)) {
                    free(cellBuffer);
                    array_free_contents(&headers);
                    array_free_contents(&row);
                    return 6;
                }
            }
            continue;
        }

        if (!inQuotes && current == ',') {
            if (!push_current_cell(&row, cellBuffer)) {
                free(cellBuffer);
                array_free_contents(&headers);
                array_free_contents(&row);
                return 7;
            }
            cellLength    = 0;
            cellBuffer[0] = '\0';
            continue;
        }

        if (!inQuotes && (current == '\n' || current == '\r')) {
            if (!push_current_cell(&row, cellBuffer)) {
                free(cellBuffer);
                array_free_contents(&headers);
                array_free_contents(&row);
                return 8;
            }
            cellLength    = 0;
            cellBuffer[0] = '\0';
            if (current == '\r') skipLf = 1;

            if (!row_is_empty(&row)) {
                if (ndjsonMode) {
                    write_ndjson_line(w, &row, row.count);
                    array_free_contents(&row);
                    array_init(&row);
                } else if (!offsetMode && headers.count == 0) {
                    size_t i;
                    for (i = 0; i < row.count; i++) {
                        if (!array_push_owned(&headers, row.items[i])) {
                            free(cellBuffer);
                            array_free_contents(&headers);
                            array_free_contents(&row);
                            return 9;
                        }
                    }
                    free(row.items);
                    row.items    = NULL;
                    row.count    = 0;
                    row.capacity = 0;
                    array_init(&row);
                    write_headers(w, &headers);
                    headersPrinted = 1;
                } else {
                    size_t colCount = offsetMode ? row.count : headers.count;
                    write_row(w, &row, colCount, &firstDataRow);
                    array_free_contents(&row);
                    array_init(&row);
                    rowCount++;
                    if (limit > 0 && rowCount >= limit) break;
                }
            } else {
                array_free_contents(&row);
                array_init(&row);
            }
            continue;
        }

        if (!append_char(&cellBuffer, &cellLength, &cellCapacity, current)) {
            free(cellBuffer);
            array_free_contents(&headers);
            array_free_contents(&row);
            return 11;
        }
    }

    /* flush last row (file has no trailing newline) */
    if (cellLength > 0 || row.count > 0) {
        if (!push_current_cell(&row, cellBuffer)) {
            free(cellBuffer);
            array_free_contents(&headers);
            array_free_contents(&row);
            return 12;
        }
        if (!row_is_empty(&row)) {
            if (ndjsonMode) {
                write_ndjson_line(w, &row, row.count);
                array_free_contents(&row);
                array_init(&row);
            } else if (!offsetMode && headers.count == 0) {
                size_t i;
                for (i = 0; i < row.count; i++) {
                    if (!array_push_owned(&headers, row.items[i])) {
                        free(cellBuffer);
                        array_free_contents(&headers);
                        array_free_contents(&row);
                        return 13;
                    }
                }
                free(row.items);
                row.items    = NULL;
                row.count    = 0;
                row.capacity = 0;
                array_init(&row);
                write_headers(w, &headers);
                headersPrinted = 1;
            } else {
                size_t colCount = offsetMode ? row.count : headers.count;
                write_row(w, &row, colCount, &firstDataRow);
                array_free_contents(&row);
                array_init(&row);
            }
        }
    }

    if (!ndjsonMode) {
        nextOffset = ftell(file);
        hasMore    = (nextOffset >= 0 && !feof(file)) ? 1 : 0;
        if (nextOffset < 0) nextOffset = 0;

        if (!headersPrinted) {
            writer_puts(w, "{\"headers\":[],\"rows\":[],\"next_offset\":0,\"has_more\":false}");
        } else {
            writer_puts(w, "],\"next_offset\":");
            writer_printf_ld(w, nextOffset);
            writer_puts(w, ",\"has_more\":");
            writer_puts(w, hasMore ? "true" : "false");
            writer_putchar(w, '}');
        }
    }

    free(cellBuffer);
    array_free_contents(&headers);
    array_free_contents(&row);
    return 0;
}

/* ── Exported FFI API ────────────────────────────────────────────────────── */

/*
 * Returns a malloc'd, null-terminated JSON string:
 *   {"headers":[...],"rows":[...],"next_offset":N,"has_more":true|false}
 * or for offset > 0:
 *   {"rows":[...],"next_offset":N,"has_more":true|false}
 *
 * Returns NULL on error. Caller must free the result with csv_free().
 */
CSV_EXPORT char *csv_read_chunk(const char *path, long offset, long limit) {
    FILE *file = fopen(path, "rb");
    if (!file) return NULL;

    Writer w;
    writer_init_buf(&w);

    int rc = csv_process(file, offset, limit, 0, &w);
    fclose(file);

    if (rc != 0 || w.error) {
        writer_free(&w);
        return NULL;
    }

    return w.buf; /* caller must free with csv_free() */
}

CSV_EXPORT void csv_free(char *ptr) {
    free(ptr);
}
