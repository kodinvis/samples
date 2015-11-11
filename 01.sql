CREATE TABLE casino_bfp_game_guids
(
  game_guid_id bigserial NOT NULL PRIMARY KEY,
  guid varchar NOT NULL,
  type_id smallint NOT NULL,
  created timestamp without time zone NOT NULL,
  user_id bigint NOT NULL,
  game_id integer NOT NULL,
  user_balance_in_game numeric(10,2) NOT NULL,
  user_balance_from_game numeric(10,2) NULL,
  closed timestamp without time zone NULL,
  is_active boolean NOT NULL DEFAULT TRUE,
  CONSTRAINT casino_bfp_game_guids_guid_unq UNIQUE (guid),
  CONSTRAINT users_fk FOREIGN KEY (user_id) REFERENCES users (user_id) ON UPDATE NO ACTION ON DELETE CASCADE,
  CONSTRAINT casino_bfp_games_fk FOREIGN KEY (game_id) REFERENCES casino_bfp_games(game_id) ON DELETE NO ACTION ON UPDATE NO ACTION
)
WITH (
  OIDS = FALSE
);
COMMENT ON COLUMN casino_bfp_game_guids.type_id IS '1 - real, 2 - fun';
CREATE INDEX casino_bfp_game_guids_user_id_idx ON casino_bfp_game_guids USING btree (user_id);
CREATE INDEX casino_bfp_game_guids_created_idx ON casino_bfp_game_guids USING btree (created);


CREATE TABLE casino_bfp_game_actions
(
  game_action_id bigserial NOT NULL PRIMARY KEY,
  game_action_type_id smallint NOT NULL,
  type varchar NOT NULL,
  created timestamp without time zone NOT NULL,
  guid varchar NOT NULL,
  session varchar NOT NULL,
  command_id bigint NOT NULL,
  round_id bigint NOT NULL,
  user_id bigint NOT NULL,
  game_id integer NOT NULL,
  user_balance_in_game numeric(10,2) NOT NULL,
  amount numeric(10,2) NOT NULL,
  type_id smallint NOT NULL,
  freegame_user_offer_id bigint NULL,
  refunded boolean NOT NULL DEFAULT false,
  CONSTRAINT casino_bfp_game_actions_command_id_unq UNIQUE (command_id),
  CONSTRAINT casino_bfp_game_guids_fk FOREIGN KEY (guid) REFERENCES casino_bfp_game_guids (guid) ON UPDATE NO ACTION ON DELETE CASCADE,
  CONSTRAINT users_fk FOREIGN KEY (user_id) REFERENCES users (user_id) ON UPDATE NO ACTION ON DELETE CASCADE,
  CONSTRAINT casino_bfp_games_fk FOREIGN KEY (game_id) REFERENCES casino_bfp_games(game_id) ON DELETE NO ACTION ON UPDATE NO ACTION
)
WITH (
  OIDS = FALSE
);
COMMENT ON COLUMN casino_bfp_game_actions.game_action_type_id IS '1 - bet, 2 - win';
COMMENT ON COLUMN casino_bfp_game_actions.type IS 'spin/freespin/bonus/chance/card/other';
COMMENT ON COLUMN casino_bfp_game_actions.type_id IS '1 - real, 2 - fun';
CREATE INDEX casino_bfp_game_actions_user_id_idx ON casino_bfp_game_actions USING btree (user_id);
CREATE INDEX casino_bfp_game_actions_created_idx ON casino_bfp_game_actions USING btree (created);


CREATE OR REPLACE VIEW v_agg_casino_daily_balance AS
(
WITH
all_dates AS
(
	SELECT (generate_series(
		now() - '31 day'::interval,
		now() - '1 day'::interval,
		'1 day'::interval))::date AS gdate
),
deposits AS
(
    SELECT d.gdate, COUNT(h.deposits_history_id) as deposits_num, COUNT(DISTINCT h.user_id) as users_num, SUM(h.amount) as amount
    FROM finance.deposits_history h
    JOIN all_dates d ON (h.dt)::date = d.gdate
    WHERE h.game_service_id = 2 AND h.finance_operation_type_id = 1
    GROUP BY d.gdate
),
bets AS
(
	SELECT * FROM
	(
        -- Old API
		(
		SELECT d.gdate, COUNT(DISTINCT s.user_id) as users_num, COUNT(DISTINCT s.game_id) as games_num, SUM(s.bets) as amount
		FROM casino_bfp_sessions s
		JOIN all_dates d ON (s.closed)::date = d.gdate
		WHERE NOT s.is_active AND NOT s.is_failed AND s.bets > 0
		GROUP BY d.gdate
		)
		UNION
        -- New API
		(
		SELECT d.gdate, COUNT(DISTINCT a.user_id) as users_num, COUNT(DISTINCT a.game_id) as games_num, SUM(a.amount) as amount
		FROM casino_bfp_game_actions a
		JOIN all_dates d ON (a.created)::date = d.gdate
		WHERE a.game_action_type_id = 1 AND NOT a.refunded
		GROUP BY d.gdate
		)
	) t
),
real_wins AS
(
    SELECT d.gdate, COUNT(DISTINCT ft.finance_account_to_id) as users_num, SUM(ft.finance_amount) as amount
    FROM finance.finance_accounts fa
    JOIN finance.finance_transactions ft ON ft.finance_account_to_id = fa.finance_account_id
	JOIN finance.finance_operations fo USING(finance_operation_id)
	JOIN all_dates d ON (fo.time)::date = d.gdate
	WHERE fo.finance_operation_type_id IN (1, 2) --Money transfer or Reverse money transfer
      AND ft.finance_account_from_id = 307851 --Casino gaming general account
    GROUP BY d.gdate
),
real_loss AS
(
    SELECT d.gdate, COUNT(DISTINCT ft.finance_account_from_id) as users_num, SUM(ft.finance_amount) as amount
    FROM finance.finance_accounts fa
    JOIN finance.finance_transactions ft ON ft.finance_account_from_id = fa.finance_account_id
	JOIN finance.finance_operations fo USING(finance_operation_id)
	JOIN all_dates d ON (fo.time)::date = d.gdate
	WHERE fo.finance_operation_type_id IN (1, 2) --Money transfer or Reverse money transfer
	  AND ft.finance_account_to_id = 307851 --Casino gaming general account
    GROUP BY d.gdate
),
bonus_wagered AS
(
    SELECT d.gdate, COUNT(DISTINCT ft.finance_account_to_id) as users_num, SUM(ft.finance_amount) as amount, SUM(COALESCE(dbp.charged_bonus_amount, 0) + COALESCE(mbp.charged_bonus_amount, 0)) as from_bonus
    FROM finance.finance_transactions ft
	JOIN finance.finance_operations fo USING(finance_operation_id)
	JOIN all_dates d ON (fo.time)::date = d.gdate
	LEFT JOIN finance.casino_promo_action_deposit_bonus_participants dbp ON dbp.won_bonus_finance_operation_id = fo.finance_operation_id
	LEFT JOIN finance.casino_promo_action_manual_bonus_participants mbp ON mbp.won_bonus_finance_operation_id = fo.finance_operation_id
	WHERE fo.finance_operation_type_id = 5 --Win bonus
		AND ft.finance_account_from_id = 307851 --Casino gaming general account
	GROUP BY d.gdate
),
balance_real AS
(
	SELECT d.gdate, SUM(h.amount) as amount
	FROM all_dates d
	LEFT JOIN finance.users_balance_history h ON h.created = d.gdate AND h.game_service_id = 2
	GROUP BY d.gdate
),
bonuses AS
(
    SELECT d.gdate,
      SUM(CASE WHEN ft.finance_account_from_id = 326045 --Casino gaming bonus account
        THEN ft.finance_amount ELSE 0 END) as charged,
      SUM(CASE WHEN ft.finance_account_to_id = 326045 --Casino gaming bonus account
        THEN ft.finance_amount ELSE 0 END) as cancelled
    FROM finance.finance_transactions ft
	JOIN finance.finance_operations fo USING(finance_operation_id)
	JOIN all_dates d ON (fo.time)::date = d.gdate
	WHERE fo.finance_operation_type_id = 4 --Bonus transfer
	GROUP BY d.gdate
),
bonus_wins AS
(
    SELECT d.gdate, COUNT(DISTINCT ft.finance_account_to_id) as users_num, SUM(ft.finance_amount) as amount
    FROM finance.finance_accounts fa
    JOIN finance.finance_transactions ft ON ft.finance_account_to_id = fa.finance_account_id
	JOIN finance.finance_operations fo USING(finance_operation_id)
	JOIN all_dates d ON (fo.time)::date = d.gdate
	WHERE fo.finance_operation_type_id IN (1, 2) --Money transfer or Reverse money transfer
      AND ft.finance_account_from_id = 326045 --Casino gaming bonus account
    GROUP BY d.gdate
),
bonus_loss AS
(
    SELECT d.gdate, COUNT(DISTINCT ft.finance_account_from_id) as users_num, SUM(ft.finance_amount) as amount
    FROM finance.finance_accounts fa
    JOIN finance.finance_transactions ft ON ft.finance_account_from_id = fa.finance_account_id
	JOIN finance.finance_operations fo USING(finance_operation_id)
	JOIN all_dates d ON (fo.time)::date = d.gdate
	WHERE fo.finance_operation_type_id IN (1, 2) --Money transfer or Reverse money transfer
      AND ft.finance_account_to_id = 326045 --Casino gaming bonus account
    GROUP BY d.gdate
),
balance_bonuses AS
(
	SELECT d.gdate, finance.f_get_total_balance_in_gameservice(d.gdate, 2::smallint, 2::smallint) as amount
	FROM all_dates d
),
adjustments AS
(
    SELECT d.gdate, coalesce((select sum(ph.amount) from finance.payouts_history ph where (ph.dt)::date = d.gdate and ph.game_service_id = 2 and ph.finance_operation_type_id = 11), 0) -
		coalesce((select sum(dh.amount) from finance.deposits_history dh where (dh.dt)::date = d.gdate and dh.game_service_id = 2 and dh.finance_operation_type_id = 11), 0) as amount
    FROM all_dates d
    WHERE coalesce((select sum(ph.amount) from finance.payouts_history ph where (ph.dt)::date = d.gdate and ph.game_service_id = 2 and ph.finance_operation_type_id = 11), 0) -
		coalesce((select sum(dh.amount) from finance.deposits_history dh where (dh.dt)::date = d.gdate and dh.game_service_id = 2 and dh.finance_operation_type_id = 11), 0) != 0
    GROUP BY d.gdate
),
comppoints AS
(
    SELECT d.gdate, COUNT(DISTINCT cpt.user_id) as users_num,
		SUM(CASE WHEN cpt.type_id = 1 THEN cpt.amount ELSE 0 END) as charged,
		SUM(CASE WHEN cpt.type_id = 2 AND cpt.action_type_id != 3 THEN cpt.amount ELSE 0 END) as withdrawn,
		SUM(CASE WHEN cpt.type_id = 2 AND cpt.action_type_id = 3 THEN cpt.amount::numeric / cpt.exchange_rate ELSE 0 END) as exchanged
    FROM finance.cp_transactions cpt
	JOIN all_dates d ON cpt.dt::date = d.gdate
    GROUP BY d.gdate
)

SELECT d.gdate, date_trunc('month', d.gdate) as mon,
	COALESCE(deposits.deposits_num, 0) AS deposits_num,
	COALESCE(deposits.users_num, 0) AS deposits_users_num,
	COALESCE(deposits.amount, 0) AS deposits_amount,
	COALESCE(bets.users_num, 0) AS bets_users_num,
	COALESCE(bets.games_num, 0) AS bets_games_num,
	COALESCE(bets.amount, 0) AS bets_amount,
    COALESCE(real_wins.users_num, 0) AS real_wins_users_num,
	COALESCE(real_wins.amount, 0) AS real_wins_amount,
    COALESCE(real_loss.users_num, 0) AS real_loss_users_num,
	COALESCE(real_loss.amount, 0) AS real_loss_amount,
	COALESCE(bonus_wagered.users_num, 0) AS bonus_wagered_users_num,
	COALESCE(bonus_wagered.amount, 0) AS bonus_wagered_amount,
	COALESCE(bonus_wagered.from_bonus, 0) AS bonus_wagered_from_bonus,
	COALESCE(balance_real.amount, 0) AS balance_real_amount,
	COALESCE(bonuses.charged, 0) AS bonuses_charged,
	COALESCE(bonuses.cancelled, 0) AS bonuses_cancelled,
	COALESCE(real_loss.amount, 0) - COALESCE(real_wins.amount, 0) - COALESCE(bonus_wagered.amount, 0) + COALESCE(adjustments.amount, 0) - COALESCE(cp.exchanged, 0) AS profit,
	COALESCE(bonus_wins.users_num, 0) AS bonus_wins_users_num,
	COALESCE(bonus_wins.amount, 0) AS bonus_wins_amount,
    COALESCE(bonus_loss.users_num, 0) AS bonus_loss_users_num,
	COALESCE(bonus_loss.amount, 0) AS bonus_loss_amount,
	COALESCE(balance_bonuses.amount, 0) AS balance_bonuses_amount,
	COALESCE(adjustments.amount, 0) AS adjustments_amount,
	COALESCE(cp.users_num, 0) AS cp_users_num,
	COALESCE(cp.charged, 0) AS cp_charged,
	COALESCE(cp.withdrawn, 0) AS cp_withdrawn,
	COALESCE(cp.exchanged, 0) AS cp_exchanged
FROM all_dates d
LEFT JOIN deposits USING (gdate)
LEFT JOIN bets USING (gdate)
LEFT JOIN real_wins USING (gdate)
LEFT JOIN real_loss USING (gdate)
LEFT JOIN bonus_wagered USING (gdate)
LEFT JOIN balance_real USING (gdate)
LEFT JOIN bonuses USING (gdate)
LEFT JOIN bonus_wins USING (gdate)
LEFT JOIN bonus_loss USING (gdate)
LEFT JOIN balance_bonuses USING (gdate)
LEFT JOIN adjustments USING (gdate)
LEFT JOIN comppoints cp USING (gdate)
ORDER BY d.gdate
);


CREATE OR REPLACE FUNCTION f_get_casino_last_user_games(in_user_id bigint, in_limit integer)
  RETURNS SETOF v_casino_bfp_games AS
$BODY$
WITH game_ids AS (
    SELECT G.game_id, MAX(S.created) as created FROM v_casino_bfp_games G
    JOIN casino_bfp_game_actions S USING(game_id)
  WHERE S.user_id = $1 AND G.is_hidden = FALSE AND G.parent_theme_id != 20 -- exclude mobile games
  GROUP BY G.game_id
  ORDER BY created DESC
  LIMIT $2
)
SELECT G.* FROM v_casino_bfp_games G
JOIN game_ids USING(game_id)
ORDER BY game_ids.created DESC;
$BODY$
  LANGUAGE sql STABLE
  COST 100
  ROWS 1000;


CREATE OR REPLACE FUNCTION f_get_last_wins()
RETURNS TABLE
(
  game_service_id	smallint,
  login		 		varchar,
  code				varchar,
  title_const_name  text,
  title				varchar,
  win_amount		numeric(10,2)
)
AS
$$
DECLARE var_check_interval integer;
DECLARE var_ggs_min numeric(10,2);
DECLARE var_mgs_min numeric(10,2);

BEGIN

SELECT option_value FROM system_options WHERE option_key = 'modal_win_check_interval' INTO var_check_interval;
SELECT option_value FROM system_options WHERE option_key = 'modal_win_ggs_min' INTO var_ggs_min;
SELECT option_value FROM system_options WHERE option_key = 'modal_win_mgs_min' INTO var_mgs_min;

RETURN QUERY

SELECT 2::smallint, u.login, g.code, g.title_const_name, g.title, a.amount
FROM casino_bfp_game_actions a
JOIN v_users_portal u USING(user_id)
JOIN v_casino_bfp_games g USING(game_id)
WHERE a.created > NOW() - var_check_interval * interval '1 second' AND a.game_action_type_id = 2 AND a.amount >= var_ggs_min

UNION

SELECT 6::smallint, u.login, g.code, g.title_const_name, g.title, a.amount
FROM vegas_game_actions a
JOIN v_users_portal u USING(user_id)
JOIN v_vegas_games g USING(game_id)
WHERE a.created > NOW() - var_check_interval * interval '1 second' AND a.game_action_type_id IN (2, 3) AND a.amount >= var_mgs_min;

END;
$$
LANGUAGE 'plpgsql'
STABLE
CALLED ON NULL INPUT
SECURITY INVOKER
ROWS 10000;