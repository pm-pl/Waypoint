-- #!sqlite
-- #{ waypoint
-- #  { create_waypoints
CREATE TABLE IF NOT EXISTS waypoints(
    uuid CHAR(36) NOT NULL,
    name VARCHAR(255) NOT NULL,
    title VARCHAR(255) NOT NULL,
    x FLOAT NOT NULL,
    y FLOAT NOT NULL,
    z FLOAT NOT NULL,
    selected TINYINT(1) NOT NULL,
    updated INTEGER NOT NULL,
    PRIMARY KEY(uuid, name)
);
-- #  }
-- #  { create_holders
CREATE TABLE IF NOT EXISTS waypoint_holders(
    uuid CHAR(36) NOT NULL PRIMARY KEY,
    display VARCHAR(255) NOT NULL,
    distance FLOAT NOT NULL
);
-- #  }
-- #  { preferences
-- #    :uuid string
SELECT display, distance FROM waypoint_holders WHERE uuid=:uuid;
-- #  }
-- #  { set_preferences
-- #    :uuid string
-- #    :display string
-- #    :distance float
REPLACE INTO waypoint_holders(uuid, display, distance) VALUES(:uuid, :display, :distance);
-- #  }
-- #  { selected
-- #    :uuid string
SELECT name, title, x, y, z FROM waypoints WHERE uuid=:uuid AND selected ORDER BY updated;
-- #  }
-- #  { get
-- #    :uuid string
-- #    :name string
SELECT name, title, x, y, z, selected FROM waypoints WHERE uuid=:uuid AND name=:name;
-- #  }
-- #  { list
-- #    :uuid string
-- #    :offset int
-- #    :length int
SELECT name, title, x, y, z, selected FROM waypoints WHERE uuid=:uuid ORDER BY name LIMIT :offset, :length;
-- #  }
-- #  { list_names
-- #    :uuid string
SELECT name FROM waypoints WHERE uuid=:uuid ORDER BY name;
-- #  }
-- #  { count
-- #    :uuid string
SELECT COUNT(1) As c FROM waypoints WHERE uuid=:uuid;
-- #  }
-- #  { count_configured
-- #    :uuid string
SELECT COUNT(1) As c FROM waypoints WHERE uuid=:uuid AND selected;
-- #  }
-- #  { set
-- #    :uuid string
-- #    :name string
-- #    :title string
-- #    :x float
-- #    :y float
-- #    :z float
-- #    :selected int
-- #    :updated int
REPLACE INTO waypoints(uuid, name, title, x, y, z, selected, updated) VALUES(:uuid, :name, :title, :x, :y, :z, :selected, :updated);
-- #  }
-- #  { delete
-- #    :uuid string
-- #    :name string
DELETE FROM waypoints WHERE uuid=:uuid AND name=:name;
-- #  }
-- #}