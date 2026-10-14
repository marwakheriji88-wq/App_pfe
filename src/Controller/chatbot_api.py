"""
API Flask - Chatbot BI texte-vers-SQL (Groq + SQLite)

Reprend la logique du notebook GrocChatBot :
  question (langage naturel) -> SQL généré par Groq -> exécution SQLite -> réponse naturelle

Contrairement au notebook (Colab, clé API saisie interactivement), ici la clé Groq
est lue depuis une variable d'environnement GROQ_API_KEY (voir instructions en bas).

Fichier nécessaire dans le même dossier : universite_dw.sql
"""

import os
import re
import sqlite3

import pandas as pd
from flask import Flask, request, jsonify
from groq import Groq

# ============================================================
# 1. CONFIGURATION
# ============================================================

GROQ_API_KEY = os.environ["GROQ_API_KEY"]  # la clé est lue dans une variable d'environnement, jamais écrite dans le code

MODEL = "openai/gpt-oss-120b"
client = Groq(api_key=GROQ_API_KEY)

SQL_PATH = os.environ.get('UNIVERSITE_DW_SQL_PATH', 'universite_dw.sql')

# ============================================================
# 2. CHARGEMENT DE LA DATABASE SQLITE (une seule fois, au démarrage)
# ============================================================

with open(SQL_PATH, encoding='utf-8') as f:
    sql = f.read()

sql = sql.replace("\\'", "''")

creates = re.findall(r'CREATE TABLE `.*?;\n', sql, re.S)
creates = [re.sub(r'\)\s*ENGINE=\w+[^;]*;', ');', c) for c in creates]
inserts = re.findall(r'INSERT INTO `.*?;\n', sql, re.S)

conn = sqlite3.connect(':memory:', check_same_thread=False)
cur = conn.cursor()
for stmt in creates:
    cur.execute(stmt)
for stmt in inserts:
    cur.execute(stmt)
conn.commit()

cur.execute("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")
TABLES = [row[0] for row in cur.fetchall()]
print(f"[chatbot] {len(TABLES)} tables chargées depuis {SQL_PATH}")


# ============================================================
# 3. SCHÉMA DE LA DATABASE
# ============================================================

def construire_schema(conn):
    cur = conn.cursor()
    cur.execute("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")
    tables = [row[0] for row in cur.fetchall()]

    schema = []
    for table in tables:
        schema.append(f"\nTABLE: {table}")

        cur.execute(f'PRAGMA table_info("{table}")')
        for col in cur.fetchall():
            column_name, column_type, primary_key = col[1], col[2], col[5]
            line = f"- {column_name} ({column_type})"
            if primary_key == 1:
                line += " [PRIMARY KEY]"
            schema.append(line)

        cur.execute(f'PRAGMA foreign_key_list("{table}")')
        for fk in cur.fetchall():
            referenced_table, from_column, to_column = fk[2], fk[3], fk[4]
            schema.append(f"- FK: {table}.{from_column} -> {referenced_table}.{to_column}")

    return "\n".join(schema)


RELATIONS = """

RELATIONS IMPORTANTES DU DATA WAREHOUSE :

fact_universite.classement_arwu_shanghai_fk
    -> dim_classement_arwu_shanghai.id

fact_universite.classement_qs_monde_fk
    -> dim_classement_qs_monde.id

fact_universite.classement_the_impact_fk
    -> dim_classement_the_impact.id

fact_universite.classement_the_monde_fk
    -> dim_classement_the_monde.id

fact_universite.country_fk
    -> dim_country.id

fact_universite.date_fk
    -> dim_date.id

fact_universite.university_fk
    -> dim_universite.university_id

dim_universite.universite_informations_fk
    -> dim_universite_details.id

dim_stars_credits.category_fk
    -> dim_category.id

dim_stars_scores.category_fk
    -> dim_category.id

dim_rapports_annuels.details_rapports_fk
    -> dim_rapports_details.id
"""

DATABASE_CONTEXT = construire_schema(conn) + RELATIONS


# ============================================================
# 4. NETTOYAGE / SÉCURITÉ DU SQL GÉNÉRÉ
# ============================================================

def nettoyer_sql(sql_text):
    if sql_text is None:
        raise ValueError("Le modèle n'a retourné aucune requête SQL.")

    sql_text = sql_text.strip()
    sql_text = re.sub(r"```sql", "", sql_text, flags=re.IGNORECASE)
    sql_text = re.sub(r"```", "", sql_text)
    sql_text = sql_text.strip()

    match = re.search(r"\b(SELECT|WITH)\b", sql_text, flags=re.IGNORECASE)
    if not match:
        raise ValueError(
            f"Aucune requête SELECT/WITH trouvée dans la réponse du modèle.\n\nRéponse reçue :\n{sql_text}"
        )

    sql_text = sql_text[match.start():].strip()
    sql_text = sql_text.rstrip(";").strip()

    if not re.match(r"^(SELECT|WITH)\b", sql_text, flags=re.IGNORECASE):
        raise ValueError("La requête générée n'est pas une requête SELECT/WITH.")

    forbidden = re.compile(
        r"\b(INSERT|UPDATE|DELETE|DROP|ALTER|CREATE|REPLACE|TRUNCATE|ATTACH|DETACH|PRAGMA|VACUUM)\b",
        flags=re.IGNORECASE,
    )
    if forbidden.search(sql_text):
        raise ValueError("Requête SQL interdite détectée.")

    if ";" in sql_text:
        raise ValueError("Plusieurs requêtes SQL ne sont pas autorisées.")

    return sql_text


# ============================================================
# 5. QUESTION -> SQL
# ============================================================

def generer_sql(question, historique):
    historique_recent = historique[-4:]
    conversation = ""
    if historique_recent:
        conversation = "\n\nCONVERSATION RÉCENTE :\n"
        for message in historique_recent:
            conversation += f"\n{message['role'].upper()} : {message['content']}"

    prompt = f"""
Tu es un expert en SQL, Business Intelligence
et analyse de données universitaires.

Tu travailles avec une base de données SQLITE.

============================================================
STRUCTURE DE LA DATABASE
============================================================

{DATABASE_CONTEXT}

{conversation}

============================================================
QUESTION DE L'UTILISATEUR
============================================================

{question}

============================================================
RÈGLES
============================================================

1. Génère UNE SEULE requête SQL.
2. La requête doit être compatible avec SQLITE.
3. Utilise uniquement les tables et colonnes présentes dans le schéma.
4. Tu peux utiliser plusieurs tables avec JOIN.
5. Utilise les relations du Data Warehouse fournies ci-dessus.
6. Ne crée jamais une colonne ou une table inexistante.
7. Utilise uniquement SELECT ou WITH.
8. INTERDIT : INSERT UPDATE DELETE DROP ALTER CREATE REPLACE TRUNCATE ATTACH DETACH PRAGMA VACUUM
9. Pour une question demandant une liste, utilise LIMIT 100.
10. Pour COUNT, AVG, SUM, MIN, MAX, etc., effectue directement le calcul dans SQL.
11. Si l'utilisateur demande une comparaison entre plusieurs années, utilise la colonne year appropriée.
12. Si l'utilisateur demande un classement, utilise la colonne de classement appropriée.
13. Les classements disponibles sont :
    - ARWU -> dim_classement_arwu_shanghai
    - QS -> dim_classement_qs_monde
    - THE -> dim_classement_the_monde
    - THE Impact -> dim_classement_the_impact
14. Si la question est ambiguë, utilise les données disponibles.
15. Retourne UNIQUEMENT la requête SQL.
16. Ne mets pas de ```sql.
17. Ne donne aucune explication.

============================================================
SQL :
============================================================
"""

    response = client.chat.completions.create(
        model=MODEL,
        messages=[
            {"role": "system", "content": "Tu es un expert SQL SQLite. Tu génères uniquement des requêtes SQL analytiques en lecture seule."},
            {"role": "user", "content": prompt},
        ],
        max_tokens=700,
        temperature=0,
    )

    return nettoyer_sql(response.choices[0].message.content)


# ============================================================
# 6. EXÉCUTION SQL
# ============================================================

def executer_sql(sql_text):
    return pd.read_sql_query(sql_text, conn)


# ============================================================
# 7. RÉSULTAT -> RÉPONSE NATURELLE
# ============================================================

def generer_reponse(question, sql_text, df_resultat):
    if df_resultat.empty:
        return "Aucune donnée ne correspond à votre question."

    resultat_texte = df_resultat.head(100).to_string(index=False)

    prompt = f"""
Tu es un assistant BI spécialisé dans l'analyse de données universitaires.

Réponds à la question de l'utilisateur en utilisant UNIQUEMENT le résultat SQL fourni.

============================================================
QUESTION
============================================================

{question}

============================================================
REQUÊTE SQL
============================================================

{sql_text}

============================================================
RÉSULTAT
============================================================

{resultat_texte}

============================================================
RÈGLES
============================================================

- Réponds en français.
- Réponds clairement et naturellement.
- Utilise les chiffres présents dans le résultat.
- Ne fabrique aucune information.
- Ne fais pas d'affirmation qui n'est pas supportée par le résultat.
- Si plusieurs lignes existent, présente-les clairement.
- Tu peux utiliser un tableau Markdown.
- Si le résultat est vide, indique-le.
- Ne parle pas de Groq. Ne parle pas de SQLite. Ne parle pas de la génération de la requête SQL.
"""

    response = client.chat.completions.create(
        model=MODEL,
        messages=[
            {"role": "system", "content": "Tu es un assistant BI. Réponds uniquement à partir des résultats fournis."},
            {"role": "user", "content": prompt},
        ],
        max_tokens=700,
        temperature=0.2,
    )

    return response.choices[0].message.content


# ============================================================
# 8. API FLASK
# ============================================================

app = Flask(__name__)


@app.route('/')
def home():
    return jsonify({'status': 'ok', 'tables': len(TABLES)})


@app.route('/chatbot/ask', methods=['POST'])
def ask():
    """
    Entrée JSON attendue :
    {
        "question": "Quelles sont les 5 meilleures universités THE en 2025 ?",
        "history": [{"role": "user", "content": "..."}, {"role": "assistant", "content": "..."}]  # optionnel
    }
    """
    try:
        payload = request.get_json(force=True) or {}
        question = (payload.get('question') or '').strip()
        historique = payload.get('history') or []

        if not question:
            return jsonify({'error': "Le champ 'question' est requis."}), 400

        sql_genere = generer_sql(question, historique)

        try:
            df_resultat = executer_sql(sql_genere)
        except Exception as e:
            return jsonify({'error': f"Erreur lors de l'exécution SQL : {e}", 'sql': sql_genere}), 400

        reponse = generer_reponse(question, sql_genere, df_resultat)

        return jsonify({
            'response': reponse,
            'sql': sql_genere,
            'row_count': int(len(df_resultat)),
        })

    except Exception as e:
        return jsonify({'error': str(e)}), 400


if __name__ == '__main__':
    app.run(debug=True, port=5001)