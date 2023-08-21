-- --------------------------------------------------------
--
-- CLEANUP: Drop existing tables and views.
--
DROP TABLE IF EXISTS `wxobs`;
DROP VIEW IF EXISTS `wxobs_agg_ymd`;
DROP VIEW IF EXISTS `wxobs_agg_ymdh`;
DROP VIEW IF EXISTS `wxobs_agg_ym`;

-- --------------------------------------------------------
--
-- TABLE: Observation data collected from La Crosse View.
-- * Unix-timestamp ("ts")
-- * Sensor ID ("sensor")
-- * Field name ("field")
-- * Unit of measurement ("unit")
-- * Measurement data ("measurement")
--
CREATE TABLE `wxobs` (
`ts` int(10) UNSIGNED NOT NULL,
`sensor` varchar(10) NOT NULL,
`field` varchar(30) NOT NULL,
`unit` varchar(30) NOT NULL,
`measurement` float NOT NULL
);
ALTER TABLE `wxobs`
ADD PRIMARY KEY (`ts`, `sensor`, `field`);

-- --------------------------------------------------------
--
-- VIEW: Aggregate data by year and month.
-- * Max ("high")
-- * Min ("low")
-- * Avg ("mean")
-- * Standard Deviation ("sd")
-- * Observation count ("cnt")
--
CREATE VIEW `wxobs_agg_ym` AS
SELECT date_format(from_unixtime(`wxobs`.`ts`), '%Y-%m') AS `ym`,
  `wxobs`.`sensor` AS `sensor`,
  `wxobs`.`field` AS `field`,
  `wxobs`.`unit` AS `unit`,
  max(`wxobs`.`measurement`) AS `high`,
  min(`wxobs`.`measurement`) AS `low`,
  round(avg(`wxobs`.`measurement`), 2) AS `mean`,
  round(std(`wxobs`.`measurement`), 2) AS `sd`,
  count(`wxobs`.`measurement`) AS `cnt`
FROM `wxobs`
GROUP BY `ym`,
  `wxobs`.`sensor`,
  `wxobs`.`field`,
  `wxobs`.`unit`;

-- --------------------------------------------------------
--
-- VIEW: Aggregate data by year, month and day.
--
CREATE VIEW `wxobs_agg_ymd` AS
SELECT date_format(from_unixtime(`wxobs`.`ts`), '%Y-%m-%d') AS `ymd`,
  `wxobs`.`sensor` AS `sensor`,
  `wxobs`.`field` AS `field`,
  `wxobs`.`unit` AS `unit`,
  max(`wxobs`.`measurement`) AS `high`,
  min(`wxobs`.`measurement`) AS `low`,
  round(avg(`wxobs`.`measurement`), 2) AS `mean`,
  round(std(`wxobs`.`measurement`), 2) AS `sd`,
  count(`wxobs`.`measurement`) AS `cnt`
FROM `wxobs`
GROUP BY `ymd`,
  `wxobs`.`sensor`,
  `wxobs`.`field`,
  `wxobs`.`unit`;

-- --------------------------------------------------------
--
-- VIEW: Aggregate data by year, month, day and hour. Note 
-- that observations are limited to the last 10 days only.
--
CREATE VIEW `wxobs_agg_ymdh` AS
SELECT date_format(from_unixtime(`wxobs`.`ts`), '%Y-%m-%d-%H') AS `ymdh`,
  `wxobs`.`sensor` AS `sensor`,
  `wxobs`.`field` AS `field`,
  `wxobs`.`unit` AS `unit`,
  max(`wxobs`.`measurement`) AS `high`,
  min(`wxobs`.`measurement`) AS `low`,
  round(avg(`wxobs`.`measurement`), 2) AS `mean`,
  round(std(`wxobs`.`measurement`), 2) AS `sd`,
  count(`wxobs`.`measurement`) AS `cnt`
FROM `wxobs`
WHERE (
    `wxobs`.`ts` > (unix_timestamp() - (((10 * 24) * 60) * 60))
  )
GROUP BY `ymdh`,
  `wxobs`.`sensor`,
  `wxobs`.`field`,
  `wxobs`.`unit`;
