/*
 * Copyright 2010-2019 Bluecherry, LLC
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License as
 * published by the Free Software Foundation; either version 2 of
 * the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

#include <stdlib.h>
#include <errno.h>
#include <string.h>
#include <pthread.h>
#include <time.h> // Required for clock_gettime
#include <stdio.h> // Required for fprintf
#include <stdbool.h> // Required for bool

#include "bc-db.h"

enum bc_db_type {
	BC_DB_SQLITE = 0,
	BC_DB_PSQL = 1,
	BC_DB_MYSQL = 2,
};

static pthread_mutex_t db_lock = PTHREAD_MUTEX_INITIALIZER;
static struct bc_db_ops *db_ops = NULL;

/* How long to wait for another thread's database operation before giving up */
#define BC_DB_LOCK_TIMEOUT_SEC 30

/* Nesting depth of db_lock in this thread; the mutex is owned while > 0 */
static thread_local int db_lock_depth = 0;

/* Returns false if the lock could not be taken. The caller must then fail its
 * operation WITHOUT touching the database: the connection is shared, and a
 * query sent while another thread is in the middle of its own corrupts both
 * (and unlocking a mutex this thread does not own releases the other
 * thread's exclusion). A database error is recoverable; that is not. */
static bool bc_db_lock(void)
{
	if (db_lock_depth > 0) {
		/* Already ours (inside a transaction of this thread) */
		db_lock_depth++;
		return true;
	}

	struct timespec timeout;
	clock_gettime(CLOCK_REALTIME, &timeout);
	timeout.tv_sec += BC_DB_LOCK_TIMEOUT_SEC;

	int ret = pthread_mutex_timedlock(&db_lock, &timeout);
	if (ret != 0) {
		bc_log(Error, "Database is busy: lock not obtained in %d s (%s), operation not executed",
			BC_DB_LOCK_TIMEOUT_SEC, strerror(ret));
		return false;
	}

	db_lock_depth = 1;
	return true;
}

static void bc_db_unlock(void)
{
	if (db_lock_depth <= 0)
		return;

	if (--db_lock_depth == 0)
		pthread_mutex_unlock(&db_lock);
}

int bc_db_start_trans(void)
{
	int ret = 0;

	if (!bc_db_lock()) {
		bc_log(Error, "Cannot start transaction - database lock unavailable");
		return -1;
	}

	if (db_ops->start_trans)
		ret = db_ops->start_trans();

	if (ret)
		bc_db_unlock();

	return ret;
}

int bc_db_commit_trans(void)
{
	int ret = 0;

	if (db_lock_depth <= 0)
		return -1;

	if (db_ops->commit_trans)
		ret = db_ops->commit_trans();
	if (!ret)
		bc_db_unlock();
	return ret;
}

int bc_db_rollback_trans(void)
{
	int ret = 0;

	if (db_lock_depth <= 0)
		return -1;

	if (db_ops->rollback_trans)
		ret = db_ops->rollback_trans();
	bc_db_unlock();
	return ret;
}

void bc_db_close(void)
{
	if (db_ops == NULL)
		return;

	db_ops->close();
	db_ops = NULL;
}

int bc_db_open(const char *bc_config)
{
	struct config_t cfg;
	int type;
	int ret = -1;

	if (db_ops != NULL)
		return 0;

	if (!bc_config) {
		bc_log(Fatal, "No configuration file specified");
		return -1;
	}

	config_init(&cfg);

	if (config_read_file(&cfg, bc_config) == CONFIG_FALSE) {
		bc_log(Fatal, "Configuration Error: %s at line %d",
		       config_error_text(&cfg), config_error_line(&cfg));
		goto db_error;
	}

	if (!config_lookup_int(&cfg, BC_CONFIG_DB ".type", &type)) {
		bc_log(Fatal, "Could not get database type from configuration");
		goto db_error;
	}

	switch (type) {
	case BC_DB_MYSQL:
		db_ops = &bc_db_mysql;
		break;
	default:
		bc_log(Fatal, "Database type %d is not supported", type);
	}

	if (db_ops)
		ret = db_ops->open(&cfg);

db_error:
	if (ret)
		db_ops = NULL;

	config_destroy(&cfg);

	return ret;
}

int __bc_db_query(const char *sql, ...)
{
	va_list ap;
	char *query;
	int ret;

	va_start(ap, sql);
	if (vasprintf(&query, sql, ap) < 0)
		return -1;
	va_end(ap);

	ret = db_ops->query(query);
	free(query);

	return ret;
}

int bc_db_query(const char *sql, ...)
{
	va_list ap;
	char *query;
	int ret;

	// CRITICAL FIX: Add null pointer protection
	if (!db_ops) {
		bc_log(Error, "Database not initialized - cannot execute query");
		return -1;
	}

	va_start(ap, sql);
	if (vasprintf(&query, sql, ap) < 0)
		return -1;
	va_end(ap);

	if (!bc_db_lock()) {
		free(query);
		return -1;
	}
	ret = db_ops->query(query);
	bc_db_unlock();

	free(query);

	return ret;
}

const char *bc_db_get_field(BC_DB_RES dbres, int nfield)
{
	return db_ops->get_field(dbres, nfield);
}

int bc_db_num_fields(BC_DB_RES dbres)
{
	return db_ops->num_fields(dbres);
}

int bc_db_fetch_row(BC_DB_RES dbres)
{
	return db_ops->fetch_row(dbres);
}

BC_DB_RES bc_db_get_table(const char *sql, ...)
{
	va_list ap;
	char *query;
	BC_DB_RES dbres;

	// CRITICAL FIX: Add null pointer protection
	if (!db_ops) {
		bc_log(Error, "Database not initialized - cannot execute query");
		return NULL;
	}

	va_start(ap, sql);
	if (vasprintf(&query, sql, ap) < 0)
		return NULL;
	va_end(ap);

	if (!bc_db_lock()) {
		free(query);
		return NULL;
	}
	dbres = db_ops->get_table(query);
	bc_db_unlock();

	free(query);

	return dbres;
}

BC_DB_RES __bc_db_get_table(const char *sql, ...)
{
	va_list ap;
	char *query;
	BC_DB_RES dbres;

	// CRITICAL FIX: Add null pointer protection
	if (!db_ops) {
		bc_log(Error, "Database not initialized - cannot execute query");
		return NULL;
	}

	va_start(ap, sql);
	if (vasprintf(&query, sql, ap) < 0)
		return NULL;
	va_end(ap);

	dbres = db_ops->get_table(query);
	free(query);

	return dbres;
}

void bc_db_free_table(BC_DB_RES dbres)
{
	if (dbres)
		db_ops->free_table(dbres);
}

const char *bc_db_get_val(BC_DB_RES dbres, const char *colname, size_t *length)
{
	return db_ops->get_val(dbres, colname, length);
}

int bc_db_get_val_int(BC_DB_RES dbres, const char *colname)
{
	const char *val = bc_db_get_val(dbres, colname, NULL);

	return (val && strlen(val)) ? atoi(val) : -1;
}

float bc_db_get_val_float(BC_DB_RES dbres, const char *colname)
{
	const char *val = bc_db_get_val(dbres, colname, NULL);

	return (val && strlen(val)) ? strtof(val, NULL) : -1.00f;
}

int bc_db_get_val_bool(BC_DB_RES dbres, const char *colname)
{
	const char *val = bc_db_get_val(dbres, colname, NULL);

	return (val && strlen(val)) ? (atoi(val) ? 1 : 0) : 0;
}

/* Should be used with locks held and lockless __bc_db_query() */
long unsigned bc_db_last_insert_rowid(void)
{
	return db_ops->last_insert_rowid();
}

char *bc_db_escape_string(const char *from, size_t len)
{
	char *to;

	if (db_ops == NULL)
		return NULL;

	if (from == NULL)
		return NULL;

	to = (char*) malloc((len << 1) + 1);

	if (to == NULL)
		return NULL;

	/* MySQL requires locking around mysql_real_escape_string */
	if (!bc_db_lock()) {
		free(to);
		return NULL;
	}
	db_ops->escape_string(to, from, len);
	bc_db_unlock();

	return to;
}
