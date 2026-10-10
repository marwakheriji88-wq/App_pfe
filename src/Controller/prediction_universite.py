from flask import Flask, request, jsonify
import joblib
import pandas as pd

# --- Chargement du pipeline complet et des métadonnées ---
model = joblib.load('model_transition_universites.pkl')
metadata = joblib.load('model_metadata.pkl')

FEATURE_NUM = metadata['feature_num']
FEATURE_CAT = metadata['feature_cat']
ALL_FEATURES = FEATURE_NUM + FEATURE_CAT
VALID_CAT_FROM = set(metadata['valid_cat_from'])

app = Flask(__name__)


@app.route('/')
def home():
    return jsonify({
        'status': 'ok',
        'message': "API de prédiction de transition de catégorie universitaire.",
        'expected_fields': ALL_FEATURES,
        'valid_cat_from': sorted(VALID_CAT_FROM),
    })


@app.route('/predict/university-transition', methods=['POST'])
def predict():
    try:
        input_data = request.get_json(force=True)
        if input_data is None:
            return jsonify({'error': 'Corps JSON manquant ou invalide.'}), 400

        # Vérifie que tous les champs attendus sont présents
        missing = [f for f in ALL_FEATURES if f not in input_data]
        if missing:
            return jsonify({
                'error': 'Champs manquants dans la requête.',
                'missing_fields': missing,
                'expected_fields': ALL_FEATURES,
            }), 400

        # Construit un DataFrame à une ligne, avec les colonnes dans le bon ordre
        input_df = pd.DataFrame([{col: input_data[col] for col in ALL_FEATURES}])

        # Cast des colonnes numériques (utile si le JSON envoie des strings)
        for col in FEATURE_NUM:
            input_df[col] = pd.to_numeric(input_df[col], errors='coerce')

        if input_df[FEATURE_NUM].isnull().any().any():
            bad_cols = input_df[FEATURE_NUM].columns[input_df[FEATURE_NUM].isnull().any()].tolist()
            return jsonify({
                'error': 'Valeurs numériques invalides.',
                'invalid_fields': bad_cols,
            }), 400

        # Avertissement doux si cat_from n'est pas une valeur connue à l'entraînement
        # (le modèle peut quand même prédire, mais le résultat sera moins fiable)
        warning = None
        if input_df['cat_from'].iloc[0] not in VALID_CAT_FROM:
            warning = f"cat_from='{input_df['cat_from'].iloc[0]}' n'a pas été vu à l'entraînement " \
                      f"(valeurs connues : {sorted(VALID_CAT_FROM)})."

        # Prédiction directe : le pipeline gère le scaling + one-hot encoding en interne
        prediction = model.predict(input_df)[0]
        proba = model.predict_proba(input_df)[0]
        proba_by_class = dict(zip(model.classes_, [round(float(p), 4) for p in proba]))

        response = {
            'prediction': prediction,
            'probabilities': proba_by_class,
        }
        if warning:
            response['warning'] = warning

        return jsonify(response)

    except Exception as e:
        return jsonify({'error': str(e)}), 400


if __name__ == '__main__':
    app.run(debug=True)