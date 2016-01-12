# Database setup for Controll

This folder is used to host database schema and manage schema versions

The following files can be found here:

- `dumps` : empty folder that can can hold database dumps for testing. Database dump files 
  are not committed to the source control, as source control is not a backup system.
- `schema` : contains versioned schema files - one file for each version of the database.
  The current database schema can be generated by applying all schema files in sequential order.
- `mysql.Dockerfile` : a docker container setup that can be used for testing.

# Testing

To test schema changes, recreate and start the docker setup by running (from the root of the
working dir):

~~~
docker-compose up
~~~

Then apply schema files in order using:

~~~
mysql -h172.17.0.2 -uroot -psecret heroku_3f90e079b7e30b6 < database/schema/schema-1.sql
~~~

and so on for each schema file.

To restart the test setup, for example - if one of the schema files failed and had to be fixed -
send `CTRL-C` to the docker-compose, then run

~~~
docker rm controll_mysql_1 && docker-compose up
~~~

and try to apply the schema files again.